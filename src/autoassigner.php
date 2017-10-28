<?php
// autoassigner.php -- HotCRP helper classes for autoassignment
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class AutoassignerCosts implements JsonSerializable {
    public $assignment = 100;
    public $preference = 60;
    public $expertise_x = -200;
    public $expertise_y = -140;
    function jsonSerialize() {
        return get_object_vars($this);
    }
}

class Autoassigner extends MessageSet {
    public $conf;
    protected $pcm;
    private $badpairs = array();
    private $papersel;
    protected $acsv = null;
    protected $load;
    private $prefs;
    private $eass;
    public $prefinfo = array();
    private $pref_groups;
    private $method = self::METHOD_MCMF;
    private $balance = self::BALANCE_NEW;
    private $review_gadget = self::REVIEW_GADGET_DEFAULT;
    public $costs;
    private $progressf = array();
    protected $mcmf;
    protected $mcmf_max_cost;
    private $mcmf_round_descriptor; // for use in MCMF progress
    private $mcmf_optimizing_for; // for use in MCMF progress
    private $ndesired;
    public $profile = ["maxflow" => 0, "mincost" => 0];

    const METHOD_MCMF = 0;
    const METHOD_RANDOM = 1;
    const METHOD_STUPID = 2;

    const BALANCE_NEW = 0;
    const BALANCE_ALL = 1;

    const REVIEW_GADGET_DEFAULT = 0;
    const REVIEW_GADGET_EXPERTISE = 1;

    const ENOASSIGN = 1;
    const EOTHERASSIGN = 2; // order matters
    const EOLDASSIGN = 3;
    const ENEWASSIGN = 4;

    function __construct(Conf $conf, $papersel) {
        $this->conf = $conf;
        $this->select_pc(array_keys($this->conf->pc_members()));
        $this->papersel = $papersel;
        $this->costs = new AutoassignerCosts;
    }

    function select_pc($pcids) {
        $this->pcm = $this->load = [];
        $pcids = array_flip($pcids);
        foreach ($this->conf->pc_members() as $cid => $p)
            if (isset($pcids[$cid])) {
                $this->pcm[$cid] = $p;
                $this->load[$cid] = 0;
            }
        return count($this->pcm);
    }

    function avoid_pair_assignment($pc1, $pc2) {
        if (!is_numeric($pc1)) {
            $pc1 = $this->conf->pc_member_by_email($pc1);
            $pc1 = $pc1 ? $pc1->contactId : null;
        }
        if (!is_numeric($pc2)) {
            $pc2 = $this->conf->pc_member_by_email($pc2);
            $pc2 = $pc2 ? $pc2->contactId : null;
        }
        if ($pc1 && $pc2)
            $this->badpairs[$pc1][$pc2] = $this->badpairs[$pc2][$pc1] = true;
    }

    function set_balance($balance) {
        $this->balance = $balance;
    }

    function set_method($method) {
        $this->method = $method;
    }

    function set_review_gadget($review_gadget) {
        $this->review_gadget = $review_gadget;
    }

    function add_progressf($progressf) {
        $this->progressf[] = $progressf;
    }

    private function set_progress($status) {
        foreach ($this->progressf as $progressf)
            call_user_func($progressf, $status);
    }

    function paper_ids() {
        return $this->papersel;
    }

    function contact_ids() {
        return array_keys($this->pcm);
    }

    function contact_email($cid) {
        return isset($this->pcm[$cid]) ? $this->pcm[$cid]->email : false;
    }


    private function balance_reviews($reviewtype) {
        $q = "select contactId, count(reviewId) from PaperReview where contactId ?a";
        if ($reviewtype)
            $q .= " and reviewType={$reviewtype}";
        $result = $this->conf->qe($q . " group by contactId", array_keys($this->pcm));
        while (($row = edb_row($result)))
            $this->load[(int) $row[0]] = (int) $row[1];
        Dbl::free($result);
    }

    private function reset_prefs() {
        $this->prefs = $this->eass = [];
        foreach ($this->pcm as $cid => $p)
            $this->prefs[$cid] = $this->eass[$cid] = array_fill_keys($this->papersel, 0);
    }

    private function preferences_review($reviewtype) {
        $time = microtime(true);
        $this->reset_prefs();

        // first load refusals
        $result = $this->conf->qe("select paperId, contactId from PaperReviewRefused where paperId ?a", $this->papersel);
        while (($row = edb_row($result)))
            $this->eass[(int) $row[1]][(int) $row[0]] = self::ENOASSIGN;

        // then load preferences
        $result = $this->conf->paper_result(null, ["paperId" => $this->papersel, "topics" => true, "allReviewerPreference" => true, "allConflictType" => true, "reviewSignatures" => true, "tags" => $this->conf->check_track_sensitivity(Track::ASSREV)]);
        $nmade = 0;
        while (($row = PaperInfo::fetch($result, null, $this->conf))) {
            $pid = $row->paperId;
            foreach ($this->pcm as $cid => $p) {
                $px = $row->reviewer_preference($p, true);
                $rt = $row->review_type($p);
                $this->prefinfo[$cid][$pid] = $px;
                if ($rt == $reviewtype)
                    $this->eass[$cid][$pid] = self::EOLDASSIGN;
                else if ($rt)
                    $this->eass[$cid][$pid] = self::EOTHERASSIGN;
                else if ($row->conflict_type($p)
                         || !$p->can_accept_review_assignment($row))
                    $this->eass[$cid][$pid] = self::ENOASSIGN;
                $this->prefs[$cid][$pid] = max($px[0], -1000) + ($px[2] / 100);
            }
            ++$nmade;
            if ($nmade % 16 == 0)
                $this->set_progress(sprintf("Loading reviewer preferences (%.0f%% done)", $nmade * 100 / count($this->papersel)));
        }
        Dbl::free($result);
        $this->make_pref_groups();

        // need to populate review assignments for badpairs not in `pcm`
        foreach ($this->badpairs as $cid => $x)
            if (!isset($this->eass[$cid])) {
                $this->eass[$cid] = array_fill_keys($this->papersel, 0);
                $result = $this->conf->qe("select paperId from PaperReview where contactId=? and paperId ?a", $cid, $this->papersel);
                while (($row = edb_row($result)))
                    $this->eass[$cid][$row[0]] = max($this->eass[$cid][$row[0]], self::ENOASSIGN);
                Dbl::free($result);
            }

        // mark badpairs as noassign
        foreach ($this->badpairs as $cid => $bp)
            if (isset($this->pcm[$cid])) {
                foreach ($this->papersel as $pid) {
                    if ($this->eass[$cid][$pid] <= self::ENOASSIGN)
                        continue;
                    foreach ($bp as $cid2 => $x)
                        $this->eass[$cid2][$pid] = max($this->eass[$cid2][$pid], self::ENOASSIGN);
                }
            }

        $this->profile["preferences"] = microtime(true) - $time;
    }

    private function make_pref_groups() {
        $this->pref_groups = array();
        foreach ($this->pcm as $cid => $p) {
            arsort($this->prefs[$cid]);
            $last_group = null;
            $this->pref_groups[$cid] = array();
            foreach ($this->prefs[$cid] as $pid => $pref)
                if (!$last_group || $pref != $last_group->pref) {
                    $last_group = (object) array("pref" => $pref, "pids" => array($pid));
                    $this->pref_groups[$cid][] = $last_group;
                } else
                    $last_group->pids[] = $pid;
            reset($this->pref_groups[$cid]);
        }
    }

    private function make_assignment($action, $round, $cid, $pid, &$papers) {
        if (!$this->acsv)
            $this->acsv = array("paper,action,email,round");
        $this->acsv[] = "$pid,$action," . $this->pcm[$cid]->email . $round;
        $this->eass[$cid][$pid] = self::ENEWASSIGN;
        $papers[$pid]--;
        $this->load[$cid]++;
        if (isset($this->badpairs[$cid]))
            foreach ($this->badpairs[$cid] as $cid2 => $x)
                $this->eass[$cid2][$pid] = max($this->eass[$cid2][$pid], self::ENOASSIGN);
    }

    private function action_takes_badpairs($action) {
        return $action !== "lead" && $action !== "shepherd";
    }

    private function assign_desired(&$papers, $nperpc) {
        if ($nperpc)
            return $nperpc * count($this->pcm);
        $n = 0;
        foreach ($papers as $ct)
            $n += max($ct, 0);
        return $n;
    }

    // This assignment function assigns without considering preferences.
    private function assign_stupidly(&$papers, $action, $round, $nperpc) {
        $ndesired = $this->assign_desired($papers, $nperpc);
        $nmade = 0;
        $pcm = $this->pcm;
        while (count($pcm)) {
            // choose a pc member at random, equalizing load
            $pc = null;
            foreach ($pcm as $pcx => $p)
                if ($pc === null
                    || $this->load[$pcx] < $this->load[$pc]) {
                    $numminpc = 0;
                    $pc = $pcx;
                } else if ($this->load[$pcx] == $this->load[$pc]) {
                    $numminpc++;
                    if (mt_rand(0, $numminpc) == 0)
                        $pc = $pcx;
                }

            // select a paper
            $apids = array_keys(array_filter($papers, function ($ct) { return $ct > 0; }));
            while (count($apids)) {
                $pididx = mt_rand(0, count($apids) - 1);
                $pid = $apids[$pididx];
                array_splice($apids, $pididx, 1);
                if ($this->eass[$pc][$pid])
                    continue;
                // make assignment
                $this->make_assignment($action, $round, $pc, $pid, $papers);
                // report progress
                ++$nmade;
                if ($nmade % 10 == 0)
                    $this->set_progress(sprintf("Making assignments stupidly (%.0f%% done)", $nmade * 100 / $ndesired + 0.5));
                break;
            }

            // if have exhausted preferences, remove pc member
            if (!$apids || $this->load[$pc] === $nperpc)
                unset($pcm[$pc]);
        }
    }

    private function assign_randomly(&$papers, $action, $round, $nperpc) {
        $pref_unhappiness = $pref_dist = array_fill_keys(array_keys($this->pcm), 0);
        $pcids = array_keys($this->pcm);
        $ndesired = $this->assign_desired($papers, $nperpc);
        $nmade = 0;
        $pcm = $this->pcm;
        while (count($pcm)) {
            // choose a pc member at random, equalizing load
            $pc = null;
            foreach ($pcm as $pcx => $p)
                if ($pc === null
                    || $this->load[$pcx] < $this->load[$pc]
                    || ($this->load[$pcx] == $this->load[$pc]
                        && $pref_unhappiness[$pcx] > $pref_unhappiness[$pc])) {
                    $numminpc = 0;
                    $pc = $pcx;
                } else if ($this->load[$pcx] == $this->load[$pc]
                           && $pref_unhappiness[$pcx] == $pref_unhappiness[$pc]) {
                    $numminpc++;
                    if (mt_rand(0, $numminpc) == 0)
                        $pc = $pcx;
                }

            // traverse preferences in descending order until encountering an
            // assignable paper
            $pg = null;
            while ($this->pref_groups[$pc]
                   && ($pg = current($this->pref_groups[$pc]))) {
                // create copy of pids for assignment
                if (!isset($pg->apids) || $pg->apids === null)
                    $pg->apids = $pg->pids;
                // skip if no papers left
                if (!count($pg->apids)) {
                    next($this->pref_groups[$pc]);
                    ++$pref_dist[$pc];
                    continue;
                }
                // pick a random paper at current preference level
                $pididx = mt_rand(0, count($pg->apids) - 1);
                $pid = $pg->apids[$pididx];
                array_splice($pg->apids, $pididx, 1);
                // skip if not assignable
                if (!isset($papers[$pid]) || $papers[$pid] <= 0 || $this->eass[$pc][$pid])
                    continue;
                // make assignment
                $this->make_assignment($action, $round, $pc, $pid, $papers);
                $pref_unhappiness[$pc] += $pref_dist[$pc];
                // report progress
                ++$nmade;
                if ($nmade % 10 == 0)
                    $this->set_progress(sprintf("Making assignments (%.0f%% done)", $nmade * 100 / $ndesired));
                break;
            }

            // if have exhausted preferences, remove pc member
            if (!$pg || $this->load[$pc] === $nperpc)
                unset($pcm[$pc]);
        }
    }

    protected function mcmf_start() {
        $this->mcmf = new MinCostMaxFlow;
        $this->mcmf->add_progressf([$this, "mcmf_progress"]);
        $this->mcmf_max_cost = null;
        $this->set_progress("Preparing assignment optimizer");
        return $this->mcmf;
    }

    protected function mcmf_stop() {
        $this->mcmf->clear(); // break circular refs
        $this->profile["maxflow"] += $this->mcmf->maxflow_end_at - $this->mcmf->maxflow_start_at;
        if ($this->mcmf->mincost_start_at) {
            $this->profile["mincost"] += $this->mcmf->mincost_end_at - $this->mcmf->mincost_start_at;
        }
        $this->mcmf = null;
    }

    function mcmf_progress($mcmf, $what, $phaseno = 0, $nphases = 0) {
        if ($what <= MinCostMaxFlow::PMAXFLOW_DONE) {
            $n = min(max($mcmf->current_flow(), 0), $this->ndesired);
            $ndesired = max($this->ndesired, 1);
            $this->set_progress($this->mcmf_message(-1, -1, $n * 100 / $ndesired));
        } else {
            $cost = $mcmf->current_cost();
            $percentage = -1;
            if (!$this->mcmf_max_cost) {
                $this->mcmf_max_cost = $cost;
            } else if ($cost < $this->mcmf_max_cost) {
                $percentage = ($this->mcmf_max_cost - $cost) * 100 / abs($this->mcmf_max_cost);
            }
            $this->set_progress($this->mcmf_message($phaseno, $nphases, $percentage));
        }
    }

    function mcmf_message($phase, $nphases, $percentage) {
        if ($phase < 0) {
            return sprintf("Preparing unoptimized assignment%s (%.0f%% done)", $this->mcmf_round_descriptor, $percentage);
        } else {
            $pmsg = $percentage >= 0 ? sprintf(" (%.1f%% better)", $percentage) : "";
            if ($nphases == 1) {
                return $this->mcmf_optimizing_for . $this->mcmf_round_descriptor . $pmsg;
            } else {
                return sprintf("%s%s, phase %d/%d%s", $this->mcmf_optimizing_for, $this->mcmf_round_descriptor, $phaseno + 1, $nphases, $pmsg);
            }
        }
    }

    private function assign_mcmf_once(&$papers, $action, $round, $nperpc) {
        $m = $this->mcmf_start();
        $this->ndesired = $this->assign_desired($papers, $nperpc);
        // existing assignment counts
        $ceass = array_fill_keys(array_keys($this->pcm), 0);
        $peass = array_fill_keys($this->papersel, 0);
        foreach ($this->eass as $cid => $ps) {
            foreach ($ps as $pid => $at)
                if (($at == self::ENEWASSIGN
                     || ($at >= self::EOTHERASSIGN && $this->balance !== self::BALANCE_NEW))
                    && isset($peass[$pid])) {
                    ++$ceass[$cid];
                    ++$peass[$pid];
                }
        }
        // paper nodes
        $nass = 0;
        foreach ($papers as $pid => $ct) {
            if (($tct = $peass[$pid] + $ct) <= 0)
                continue;
            $m->add_node("p$pid", "p");
            $m->add_edge("p$pid", ".sink", $tct, 0, $peass[$pid]);
            if ($this->review_gadget == self::REVIEW_GADGET_EXPERTISE) {
                $m->add_node("p{$pid}x", "px");
                $m->add_node("p{$pid}y", "py");
                $m->add_node("p{$pid}xy", "pxy");
                $m->add_edge("p{$pid}x", "p{$pid}xy", 1, $this->costs->expertise_x);
                $m->add_edge("p{$pid}x", "p{$pid}y", $tct, 0);
                $m->add_edge("p{$pid}y", "p{$pid}xy", 2, $this->costs->expertise_y);
                $m->add_edge("p{$pid}y", "p$pid", $tct, 0);
                $m->add_edge("p{$pid}xy", "p$pid", 2, 0);
            }
            $nass += $ct;
        }
        // user nodes
        $assperpc = ceil($nass / count($this->pcm));
        $minload = $this->load ? min($this->load) : 0;
        $maxload = ($this->load ? max($this->load) : 0) + $assperpc;
        foreach ($this->pcm as $cid => $p) {
            $m->add_node("u$cid", "u");
            if ($nperpc)
                $m->add_edge(".source", "u$cid", $nperpc, 0);
            else {
                for ($l = $this->load[$cid]; $l < $maxload; ++$l)
                    $m->add_edge(".source", "u$cid", 1, $this->costs->assignment * ($l - $minload));
            }
            if ($ceass[$cid])
                $m->add_edge(".source", "u$cid", $ceass[$cid], 0, $ceass[$cid]);
        }
        // cost determination
        $cost = array();
        foreach ($this->pcm as $cid => $p) {
            $ppg = $this->pref_groups[$cid];
            foreach ($ppg as $pgi => $pg) {
                $adjusted_pgi = (int) ($pgi * $this->costs->preference / count($ppg));
                foreach ($pg->pids as $pid)
                    $cost[$cid][$pid] = $adjusted_pgi;
            }
        }
        // figure out badpairs class for each user
        $bpclass = array();
        if ($this->action_takes_badpairs($action)) {
            foreach ($this->badpairs as $cid1 => $bp) {
                foreach ($bp as $cid2 => $x)
                    if (isset($this->pcm[$cid1]) && isset($this->pcm[$cid2]))
                        $bpclass[$cid1][$cid2] = $bpclass[$cid1][$cid1] = true;
            }
            foreach ($bpclass as $cid => &$x)
                $x = min(array_keys($x));
            unset($x);
        }
        // paper <-> contact map
        $bpdone = array();
        foreach ($papers as $pid => $ct) {
            if ($ct <= 0 && $peass[$pid] <= 0)
                continue;
            foreach ($this->pcm as $cid => $p) {
                $eass = $this->eass[$cid][$pid];
                if ($eass == self::ENOASSIGN
                    || ($eass && $eass < self::ENEWASSIGN && $this->balance == self::BALANCE_NEW)
                    || (!$eass && $ct <= 0))
                    continue;
                if (isset($bpclass[$cid])) {
                    $dst = "b{$pid}." . $bpclass[$cid];
                    if (!$m->node_exists($dst)) {
                        $m->add_node($dst, "b");
                        $m->add_edge($dst, "p$pid", 1, 0);
                    }
                } else if ($this->review_gadget == self::REVIEW_GADGET_EXPERTISE) {
                    $exp = $this->prefinfo[$cid][$pid][1];
                    if ($exp > 0)
                        $dst = "p{$pid}x";
                    else if ($exp === 0)
                        $dst = "p{$pid}y";
                    else
                        $dst = "p$pid";
                } else
                    $dst = "p$pid";
                $m->add_edge("u$cid", $dst, 1, $cost[$cid][$pid], $eass ? 1 : 0);
            }
        }
        // run MCMF
        $m->shuffle();
        $m->run();
        // make assignments
        $this->set_progress("Completing assignment" . $this->mcmf_round_descriptor);
        $nassigned = 0;
        foreach ($this->pcm as $cid => $p) {
            foreach ($m->reachable("u$cid", "p") as $v) {
                $pid = substr($v->name, 1);
                if (!$this->eass[$cid][$pid]) {
                    $this->make_assignment($action, $round, $cid, $pid, $papers);
                    ++$nassigned;
                }
            }
        }
        $this->mcmf_stop();
        return $nassigned;
    }

    private function assign_mcmf(&$papers, $action, $round, $nperpc) {
        $this->mcmf_round_descriptor = "";
        $this->mcmf_optimizing_for = "Optimizing assignment for preferences and balance";
        $mcmf_round = 1;
        while ($this->assign_mcmf_once($papers, $action, $round, $nperpc)) {
            $nmissing = 0;
            foreach ($papers as $pid => $ct)
                if ($ct > 0)
                    $nmissing += $ct;
            $navailable = 0;
            if ($nperpc) {
                foreach ($this->pcm as $cid => $p)
                    $navailable += max($nperpc - $this->load[$cid], 0);
            }
            if ($nmissing == 0 || $navailable == 0)
                break;
            ++$mcmf_round;
            $this->mcmf_round_descriptor = ", round $mcmf_round";
        }
    }

    private function assign_method(&$papers, $action, $round, $nperpc) {
        if ($this->method == self::METHOD_RANDOM)
            $this->assign_randomly($papers, $action, $round, $nperpc);
        else if ($this->method == self::METHOD_STUPID)
            $this->assign_stupidly($papers, $action, $round, $nperpc);
        else
            $this->assign_mcmf($papers, $action, $round, $nperpc);
    }


    private function check_missing_assignments(&$papers, $action) {
        ksort($papers);
        $badpids = array();
        foreach ($papers as $pid => $n)
            if ($n > 0)
                $badpids[] = $pid;
        if (!count($badpids))
            return;
        $b = array();
        $pidx = join("+", $badpids);
        foreach ($badpids as $pid)
            $b[] = "<a href='" . hoturl("assign", "p=$pid&amp;ls=$pidx") . "'>$pid</a>";
        $x = "";
        if ($action === "rev" || $action === "revadd")
            $x = ", possibly because of conflicts or previously declined reviews in the PC members you selected";
        else
            $x = ", possibly because the selected PC members didn’t review these papers";
        $y = (count($b) > 1 ? " (<a class='nw' href='" . hoturl("search", "q=$pidx") . "'>list them</a>)" : "");
        $this->conf->warnMsg("I wasn’t able to complete the assignment$x.  The following papers got fewer than the required number of assignments: " . join(", ", $b) . $y . ".");
    }

    private function analyze_reviewtype($reviewtype, $round) {
        if ($reviewtype == REVIEW_PRIMARY)
            $action = "primary";
        else if ($reviewtype == REVIEW_SECONDARY)
            $action = "secondary";
        else
            $action = "pcreview";
        return array($action, $round ? ",$round" : "");
    }

    function run_reviews_per_pc($reviewtype, $round, $nass) {
        $this->preferences_review($reviewtype);
        $papers = array_fill_keys($this->papersel, ceil((count($this->pcm) * ($nass + 2)) / count($this->papersel)));
        list($action, $round) = $this->analyze_reviewtype($reviewtype, $round);
        $this->assign_method($papers, $action, $round, $nass);
    }

    function run_more_reviews($reviewtype, $round, $nass) {
        if ($this->balance !== self::BALANCE_NEW)
            $this->balance_reviews($reviewtype);
        $this->preferences_review($reviewtype);
        $papers = array_fill_keys($this->papersel, $nass);
        list($action, $round) = $this->analyze_reviewtype($reviewtype, $round);
        $this->assign_method($papers, $action, $round, null);
        $this->check_missing_assignments($papers, "revadd");
    }

    function run_ensure_reviews($reviewtype, $round, $nass) {
        if ($this->balance !== self::BALANCE_NEW)
            $this->balance_reviews($reviewtype);
        $this->preferences_review($reviewtype);
        $papers = array_fill_keys($this->papersel, $nass);
        $result = $this->conf->qe("select paperId, count(reviewId) from PaperReview where reviewType={$reviewtype} group by paperId");
        while (($row = edb_row($result)))
            if (isset($papers[$row[0]]))
                $papers[$row[0]] = max($nass - $row[1], 0);
        Dbl::free($result);
        list($action, $round) = $this->analyze_reviewtype($reviewtype, $round);
        $this->assign_method($papers, $action, $round, null);
        $this->check_missing_assignments($papers, "rev");
    }


    function assignments() {
        return count($this->acsv) > 1 ? $this->acsv : null;
    }

    function pc_unhappiness() {
        if (!$this->prefs)
            return array();

        $ubypid = array();
        foreach ($this->pcm as $cid => $p) {
            $u = array();
            foreach ($this->pref_groups[$cid] as $i => $pg)
                foreach ($pg->pids as $pid)
                    $u[$pid] = $i;
            $ubypid[$cid] = $u;
        }

        $u = array_fill_keys(array_keys($this->pcm), 0);
        foreach ($this->eass as $cid => $m) {
            foreach ($m as $pid => $x)
                if ($x === self::ENEWASSIGN)
                    $u[$cid] += $ubypid[$cid][$pid];
        }
        return $u;
    }

    function has_tentative_assignment() {
        return !empty($this->acsv) || $this->mcmf;
    }

    function tentative_assignment_map() {
        $pcmap = $a = array();
        foreach ($this->pcm as $cid => $p) {
            $pcmap[$p->email] = $cid;
            $a[$cid] = array();
        }
        foreach ($this->acsv as $atext) {
            $arow = explode(",", $atext);
            $a[$pcmap[$arow[2]]][$arow[0]] = true;
        }
        if (($m = $this->mcmf)) {
            foreach ($this->pcm as $cid => $p) {
                foreach ($m->reachable("u$cid", "p") as $v)
                    $a[$cid][substr($v->name, 1)] = true;
            }
        }
        return $a;
    }
}

class PrefConflict_Autoassigner extends Autoassigner {
    function __construct(Conf $conf, Qrequest $qreq) {
        parent::__construct($conf);
    }
    function run() {
        $papers = array_fill_keys($this->paper_ids(), 1);
        $result = $this->conf->qe_raw($this->conf->preferenceConflictQuery("all", ""));
        $this->acsv = ["paper,action,email"];
        while (($row = edb_row($result))) {
            if (isset($papers[$row[0]]) && ($email = $this->contact_email($row[1]))) {
                $this->acsv[] = "{$row[0]},conflict,{$email}";
                $this->prefinfo[(int) $row[1]][(int) $row[0]] = $row[2];
            }
        }
        Dbl::free($result);
    }
}

class ClearReview_Autoassigner extends Autoassigner {
    private $action;
    private $reviewtypes;
    function __construct(Conf $conf, Qrequest $qreq) {
        parent::__construct($conf);
        if ($qreq->cleartype == REVIEW_META
            || $qreq->cleartype == REVIEW_PRIMARY
            || $qreq->cleartype == REVIEW_SECONDARY
            || $qreq->cleartype == REVIEW_PC) {
            $this->action = "noreview";
            $this->reviewtypes = [(int) $qreq->cleartype];
        } else if ($qreq->cleartype === "conflict") {
            $this->action = "noconflict";
        } else if ($qreq->cleartype === "lead" || $qreq->cleartype === "shepherd") {
            $this->action = "no" . $qreq->cleartype;
        } else {
            $this->error_at("cleartype", "Unknown clear action.");
        }
    }
    function run() {
        if ($this->action === "noreview") {
            $result = $this->conf->qe("select paperId, contactId from PaperReview where paperId?a and contactId?a and reviewType?a", $this->paper_ids(), $this->contact_ids(), $this->reviewtypes);
        } else if ($this->action === "noconflict") {
            $result = $this->conf->qe("select paperId, contactId from PaperConflict where paperId?a and contactId?a and conflictType>0 and conflictType<?", $this->paper_ids(), $this->contact_ids(), CONFLICT_AUTHOR);
        } else if ($this->action === "nolead" || $this->action === "noshepherd") {
            $pctype = substr($this->action, 2);
            $result = $this->conf->qe("select paperId, {$pctype}ContactId from Paper where paperId?a and {$pctype}ContactId?a", $this->paper_ids(), $this->contact_ids());
        }
        $this->acsv = ["paper,action,email"];
        while ($result && ($row = $result->fetch_row())) {
            $this->acsv[] = "{$row[0]},{$this->action}," . $this->contact_email($row[1]);
        }
        Dbl::free($result);
    }
}

class PaperPC_Autoassigner extends Autoassigner {
    private $pctype;
    private $balance_all;
    function __construct(Conf $conf, Qrequest $qreq) {
        parent::__construct($conf);
        if ($qreq->atype === "lead" || $qreq->atype === "shepherd") {
            $this->pctype = $qreq->atype;
        } else {
            $this->error_at("atype", "Unknown paper PC action.");
        }
        $this->balance_all = $qreq->balance === "all";
    }
    private function set_load() {
        $result = $this->conf->qe("select {$this->pctype}ContactId, count(paperId) from Paper where paperId?A group by {$this->pctype}ContactId", $this->paper_ids());
        while ($result && ($row = $result->fetch_row())) {
            $this->load[(int) $row[0]] = (int) $row[1];
        }
        Dbl::free($result);
    }
    private function set_preferences($scoreinfo) {
        $time = microtime(true);
        $this->reset_prefs();

        $all_fields = $this->conf->all_review_fields();
        $scoredir = 1;
        if ($scoreinfo === "x")
            $score = "1";
        else if ((substr($scoreinfo, 0, 1) === "-"
                  || substr($scoreinfo, 0, 1) === "+")
                 && isset($all_fields[substr($scoreinfo, 1)])) {
            $score = "PaperReview." . substr($scoreinfo, 1);
            $scoredir = substr($scoreinfo, 0, 1) === "-" ? -1 : 1;
        } else
            $score = "PaperReview.overAllMerit";

// XXX $scoreinfo set in constructor
// XXX use PaperInfo for this
        $query = "select Paper.paperId, ? contactId,
            coalesce(PaperConflict.conflictType, 0) as conflictType,
            coalesce(PaperReview.reviewType, 0) as myReviewType,
            coalesce(PaperReview.reviewSubmitted, 0) as myReviewSubmitted,
            coalesce($score, 0) as reviewScore,
            Paper.outcome,
            Paper.managerContactId
        from Paper
        left join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=?)
        left join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=?)
        where Paper.paperId ?a
        group by Paper.paperId";

        $nmade = 0;
        foreach ($this->pcm as $cid => $p) {
            $result = $this->conf->qe($query, $cid, $cid, $cid, $this->papersel);

            // First, collect score extremes
            $scoreextreme = array();
            $rows = array();
            while (($row = edb_orow($result))) {
                if ($row->conflictType > 0
                    || $row->myReviewType == 0
                    || $row->myReviewSubmitted == 0
                    || $row->reviewScore == 0)
                    $this->eass[$row->contactId][$row->paperId] = self::ENOASSIGN;
                else {
                    if (!isset($scoreextreme[$row->paperId])
                        || $scoredir * $row->reviewScore > $scoredir * $scoreextreme[$row->paperId])
                        $scoreextreme[$row->paperId] = $row->reviewScore;
                    $rows[] = $row;
                }
            }
            // Then, collect preferences; ignore score differences farther
            // than 1 score away from the relevant extreme
            foreach ($rows as $row) {
                $scoredifference = $scoredir * ($row->reviewScore - $scoreextreme[$row->paperId]);
                if ($scoredifference >= -1)
                    $this->prefs[$row->contactId][$row->paperId] = $scoredifference;
            }
            unset($rows);        // don't need the memory any more

            Dbl::free($result);
            ++$nmade;
            if ($nmade % 4 == 0)
                $this->set_progress(sprintf("Loading reviewer preferences (%.0f%% done)", $nmade * 100 / count($this->pcm)));
        }
        $this->make_pref_groups();

        $this->profile["preferences"] = microtime(true) - $time;
    }
    function run_paperpc($action, $preference) {
        if ($this->balance_all)
            $this->set_load();
        $this->preferences_paperpc($preference);
        $papers = array_fill_keys($this->papersel, 0);
        $result = $this->conf->qe("select paperId from Paper where {$action}ContactId=0");
        while (($row = edb_row($result)))
            if (isset($papers[$row[0]]))
                $papers[$row[0]] = 1;
        Dbl::free($result);
        $this->assign_method($papers, $action, "", null);
        $this->check_missing_assignments($papers, $action);
    }
}

class Review_Autoassigner extends Autoassigner {

}

class DiscussionOrder_Autoassigner extends Autoassigner {
    private $tag;
    private $sequential = false;
    private $roundno;
    function __construct(Conf $conf, Qrequest $qreq) {
        parent::__construct($conf);
        $tag = trim((string) $qreq->discordertag);
        $tag = $tag === "" ? "discuss" : $tag;
        $tagger = new Tagger;
        if (($tag = $tagger->check($tag, Tagger::NOVALUE)))
            $this->tag = $tag;
        else
            $this->error_at("discordertag", $tagger->error_html);
    }
    function mcmf_message($phase, $nphases, $percentage) {
        if ($phase < 0) {
            return sprintf("Preparing unoptimized assignment (%.0f%% done)", $this->mcmf_round_descriptor, $percentage);
        } else {
            $msg = "Optimizing order" . ($this->roundno ? ", round " . ($this->roundno + 1));
            $msg .= $nphases > 1 ? sprintf(", phase %d/%d", $phaseno + 1, $nphases) : "";
            $msg .= $percentage >= 0 ? sprintf(" (%.1f%% better)", $percentage) : "";
            return $msg;
        }
    }
    private function run_discussion_order_once($cflt, $plist) {
        $m = $this->mcmf_start();
        // paper nodes
        // set p->po edge cost so low that traversing that edge will
        // definitely lower total cost; all positive costs are <=
        // count($this->pcm), so this edge should have cost:
        $pocost = -(count($this->pcm) + 1);
        $this->mcmf_max_cost = $pocost * count($plist) * 0.75;
        $m->add_node(".s", "source");
        $m->add_edge(".source", ".s", 1, 0);
        foreach ($plist as $i => $pids) {
            $m->add_node("p$i", "p");
            $m->add_node("po$i", "po");
            $m->add_edge(".s", "p$i", 1, 0);
            $m->add_edge("p$i", "po$i", 1, $pocost);
            $m->add_edge("po$i", ".sink", 1, 0);
        }
        // conflict edges
        $plist2 = $plist; // need copy for different iteration ptr
        foreach ($plist as $i => $pid1)
            foreach ($plist2 as $j => $pid2)
                if ($i != $j) {
                    $pid1 = is_array($pid1) ? $pid1[count($pid1) - 1] : $pid1;
                    $pid2 = is_array($pid2) ? $pid2[0] : $pid2;
                    // cost of edge is number of different conflicts
                    $cost = count($cflt[$pid1] + $cflt[$pid2]) - count(array_intersect($cflt[$pid1], $cflt[$pid2]));
                    $m->add_edge("po$i", "p$j", 1, $cost);
                }
        // run MCMF
        $m->shuffle();
        $m->run();
        // extract next roots
        $roots = array_keys($plist);
        $result = array();
        while (!empty($roots)) {
            $source = ".source";
            if (count($roots) !== count($plist))
                $source = "p" . $roots[mt_rand(0, count($roots) - 1)];
            $pgroup = $igroup = array();
            foreach ($m->topological_sort($source, "p") as $v) {
                $pidx = (int) substr($v->name, 1);
                $igroup[] = $pidx;
                if (is_array($plist[$pidx]))
                    $pgroup = array_merge($pgroup, $plist[$pidx]);
                else
                    $pgroup[] = $plist[$pidx];
            }
            $result[] = $pgroup;
            $roots = array_values(array_diff($roots, $igroup));
        }
        // done
        $this->mcmf_stop();
        return $result;
    }

    function run() {
        $this->acsv = [];
        $paper_ids = $this->paper_ids();
        if (empty($paper_ids)) {
            return;
        }
        // load conflicts
        $cflt = array_fill_keys($paper_ids, []);
        $result = $this->conf->qe("select paperId, contactId from PaperConflict where paperId?a and contactId?a and conflictType>0", $paper_ids, $this->contact_ids());
        while ($result && ($row = $result->fetch_row())) {
            $cflt[(int) $row[0]][] = (int) $row[1];
        }
        Dbl::free($result);
        // run max-flow
        $order = $paper_ids;
        for ($this->roundno = 0; !$this->roundno || count($order) > 1; ++$this->roundno) {
            $order = $this->run_discussion_order_once($cflt, $order);
            if (!$this->roundno) {
                $groupmap = [];
                foreach ($order as $i => $pids) {
                    foreach ($pids as $pid)
                        $groupmap[$pid] = $i;
                }
            }
        }
        // make assignments
        $this->set_progress("Completing assignment");
        $this->acsv[] = "paper,action,tag";
        $this->acsv[] = "# hotcrp_assign_display_search";
        $this->acsv[] = "# hotcrp_assign_show pcconf";
        $this->acsv[] = "all,cleartag,{$this->tag}";
        $curgroup = -1;
        $index = 0;
        $search = ["HEADING:none"];
        foreach ($order[0] as $pid) {
            if ($groupmap[$pid] != $curgroup && $curgroup != -1)
                $search[] = "THEN HEADING:none";
            $curgroup = $groupmap[$pid];
            $index += TagInfo::value_increment($this->sequential ? "aos" : "ao");
            $this->acsv[] = "{$pid},tag,{$this->tag}#{$index}";
            $search[] = $pid;
        }
        $this->acsv[1] = "# hotcrp_assign_display_search " . join(" ", $search);
        //echo Ht::unstash_script("$('#propass').before(" . json_encode_browser(Ht::pre_text_wrap($m->debug_info(true) . "\n")) . ")");
    }
}
