<?php
// t_settings.php -- HotCRP tests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Settings_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $u_chair;
    /** @var Contact
     * @readonly */
    public $u_mgbaker;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
        $this->u_mgbaker = $conf->checked_user_by_email("mgbaker@cs.stanford.edu");
    }

    function test_unambiguous_renumbering() {
        $sv = new SettingValues($this->conf->root_user());
        xassert_eqq($sv->unambiguous_renumbering(["Hello", "Hi"], ["Hello", "Hi", "Hello"]), []);
        xassert_eqq($sv->unambiguous_renumbering(["Hello", "Hi"], ["Hello", "Fart", "Hi"]), [1 => 2]);
        xassert_eqq($sv->unambiguous_renumbering(["Hello", "Hi"], ["Hi", "Hello"]), [0 => 1, 1 => 0]);
        xassert_eqq($sv->unambiguous_renumbering(["Hello", "Hi", "Fart"], ["Hi", "Hello"]), [0 => 1, 1 => 0, 2 => -1]);
        xassert_eqq($sv->unambiguous_renumbering(["Hello", "Hi", "Fart"], ["Hi", "Barf"]), [2 => -1]);
        xassert_eqq($sv->unambiguous_renumbering(["Hello", "Hi", "Fart"], ["Fart", "Barf"]), [2 => -1]);
        xassert_eqq($sv->unambiguous_renumbering(["Hello", "Hi", "Fart"], ["Fart", "Money", "Barf"]), []);
        xassert_eqq($sv->unambiguous_renumbering(["Hello", "Hi", "Fart"], ["Fart", "Hello", "Hi"]), [0 => 1, 1 => 2, 2 => 0]);
    }

    function test_setting_info() {
        $si = $this->conf->si("fmtstore_s_0");
        xassert_eqq($si->storage_type, Si::SI_DATA | Si::SI_SLICE);
        xassert_eqq($si->storage_name(), "sub_banal");
        $si = $this->conf->si("fmtstore_s_4");
        xassert_eqq($si->storage_type, Si::SI_DATA | Si::SI_SLICE);
        xassert_eqq($si->storage_name(), "sub_banal_4");
        $si = $this->conf->si("format/2/active");
        xassert_eqq($si->first_page(), "decisions");

        $si = $this->conf->si("rf/1/order");
        xassert_eqq($si->first_page(), "reviewform");

        $si = $this->conf->si("track/1/perm/view/tag");
        xassert_eqq($si->first_page(), "tags");
    }

    function test_message_defaults() {
        xassert(!$this->conf->setting("has_topics"));
        ConfInvariants::test_all($this->conf);

        $sv = SettingValues::make_request($this->u_chair, []);
        $s = $this->conf->si("preference_instructions")->default_value($sv);
        xassert(strpos($s, "review preference") !== false);
        xassert(strpos($s, "topic") === false);

        $sv = SettingValues::make_request($this->u_chair, [
            "has_topic" => 1,
            "new_topics" => "Whatever\n"
        ])->parse();

        $s = $this->conf->si("preference_instructions")->default_value($sv);
        xassert(strpos($s, "review preference") !== false);
        xassert(strpos($s, "topic") !== false);
        ConfInvariants::test_all($this->conf);

        $sv = SettingValues::make_request($this->u_chair, [
            "has_topic" => 1,
            "topic/1/name" => "Whatever",
            "topic/1/delete" => 1
        ]);
        xassert($sv->execute());

        xassert_eqq($this->conf->setting("has_topics"), null);
    }

    function delete_topics() {
        $this->conf->qe("delete from TopicInterest");
        $this->conf->qe("truncate table TopicArea");
        $this->conf->qe("alter table TopicArea auto_increment=0");
        $this->conf->qe("delete from PaperTopic");
        $this->conf->qe("delete from Settings where name='has_topics'");
    }

    function test_topics() {
        $this->delete_topics();
        xassert_eqq(json_encode($this->conf->topic_set()->as_array()), '[]');
        $sv = SettingValues::make_request($this->u_chair, [
            "has_topic" => 1,
            "new_topics" => "Fart\n   Barf"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode($this->conf->topic_set()->as_array()), '{"2":"Barf","1":"Fart"}');

        // duplicate topic not accepted
        $sv = SettingValues::make_request($this->u_chair, [
            "has_topic" => 1,
            "new_topics" => "Fart"
        ]);
        xassert(!$sv->execute());
        xassert_eqq($sv->reqstr("topic/3/name"), "Fart");
        xassert($sv->has_error_at("topic/3/name"));
        xassert_neqq(strpos($sv->full_feedback_text(), "is not unique"), false);
        xassert_eqq(json_encode($this->conf->topic_set()->as_array()), '{"2":"Barf","1":"Fart"}');

        $sv = SettingValues::make_request($this->u_chair, [
            "has_topic" => 1,
            "new_topics" => "Fart2"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode($this->conf->topic_set()->as_array()), '{"2":"Barf","1":"Fart","3":"Fart2"}');

        $sv = SettingValues::make_request($this->u_chair, [
            "has_topic" => 1,
            "topic/1/id" => "2",
            "topic/1/name" => "Fért",
            "topic/2/id" => "",
            "topic/2/name" => "Festival Fartal",
            "topic/3/id" => "new",
            "topic/3/name" => "Fet",
            "new_topics" => "Fart3"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode_db($this->conf->topic_set()->as_array()), '{"1":"Fart","3":"Fart2","6":"Fart3","2":"Fért","4":"Festival Fartal","5":"Fet"}');

        $sv = SettingValues::make_request($this->u_chair, [
            "has_topic" => 1,
            "topic/1/id" => "1",
            "topic/1/delete" => "1",
            "topic/2/id" => "2",
            "topic/2/delete" => "1",
            "topic/3/id" => "3",
            "topic/3/delete" => "1",
            "topic/4/id" => "4",
            "topic/4/delete" => "1",
            "topic/5/id" => "5",
            "topic/5/delete" => "1",
            "topic/6/id" => "6",
            "topic/6/delete" => "1"
        ]);
        xassert($sv->execute());

        xassert_eqq(json_encode($this->conf->topic_set()->as_array()), '[]');
        xassert_eqq($this->conf->setting("has_topics"), null);
        ConfInvariants::test_all($this->conf);
    }

    function test_topics_json() {
        $this->delete_topics();
        xassert_eqq(json_encode($this->conf->topic_set()->as_array()), '[]');

        $sv = (new SettingValues($this->u_chair))->add_json_string('{
            "topic": ["Barf", "Fart", "Money"]
        }');
        xassert($sv->execute());
        xassert_eqq(json_encode_db($this->conf->topic_set()->as_array()), '{"1":"Barf","2":"Fart","3":"Money"}');

        $sv = (new SettingValues($this->u_chair))->add_json_string('{
            "topic": []
        }');
        xassert($sv->execute());
        xassert_eqq(json_encode_db($this->conf->topic_set()->as_array()), '{"1":"Barf","2":"Fart","3":"Money"}');

        $sv = (new SettingValues($this->u_chair))->add_json_string('{
            "topic": [{"id": 1, "name": "Berf"}]
        }');
        xassert($sv->execute());
        xassert_eqq(json_encode_db($this->conf->topic_set()->as_array()), '{"1":"Berf","2":"Fart","3":"Money"}');

        $sv = (new SettingValues($this->u_chair))->add_json_string('{
            "topic": [{"id": 1, "name": "Berf"}]
        }', null, true);
        xassert($sv->execute());
        xassert_eqq(json_encode_db($this->conf->topic_set()->as_array()), '{"1":"Berf"}');

        $sv = (new SettingValues($this->u_chair))->add_json_string('{
            "topic": [{"id": "new", "name": "Berf"}]
        }');
        xassert(!$sv->execute());
        xassert_neqq(strpos($sv->full_feedback_text(), "is not unique"), false);

        $sv = (new SettingValues($this->u_chair))->add_json_string('{
            "topic": [{"name": "Bingle"}, {"name": "Bongle"}]
        }');
        xassert($sv->execute());
        xassert_eqq(json_encode_db($this->conf->topic_set()->as_array()), '{"1":"Berf","4":"Bingle","5":"Bongle"}');

        $this->delete_topics();
    }

    function test_decision_types() {
        $this->conf->save_refresh_setting("outcome_map", null);
        xassert_eqq(json_encode($this->conf->decision_map()), '{"0":"Unspecified","1":"Accepted","-1":"Rejected"}');
        xassert_eqq($this->conf->setting("decisions"), null);

        $sv = SettingValues::make_request($this->u_chair, [
            "has_decision" => 1,
            "decision/1/name" => "Accepted!",
            "decision/1/id" => "1",
            "decision/2/name" => "Newly accepted",
            "decision/2/id" => "new",
            "decision/2/category" => "accept"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode($this->conf->decision_map()), '{"0":"Unspecified","1":"Accepted!","2":"Newly accepted","-1":"Rejected"}');
        xassert_eqq($this->conf->setting("decisions"), null);

        $sv = SettingValues::make_request($this->u_chair, [
            "has_decision" => 1,
            "decision/1/id" => "1",
            "decision/1/delete" => "1"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode($this->conf->decision_map()), '{"0":"Unspecified","2":"Newly accepted","-1":"Rejected"}');

        // accept-category with “reject” in the name is rejected by default
        $sv = SettingValues::make_request($this->u_chair, [
            "has_decision" => 1,
            "decision/1/id" => "2",
            "decision/1/name" => "Rejected"
        ]);
        xassert(!$sv->execute());
        xassert_neqq(strpos($sv->full_feedback_text(), "Accept-category decision"), false);

        // duplicate decision names are rejected
        $sv = SettingValues::make_request($this->u_chair, [
            "has_decision" => 1,
            "decision/1/id" => "2",
            "decision/1/name" => "Rejected",
            "decision/1/name_force" => "1"
        ]);
        xassert(!$sv->execute());
        xassert_neqq(strpos($sv->full_feedback_text(), "is not unique"), false);
        xassert_eqq(json_encode($this->conf->decision_map()), '{"0":"Unspecified","2":"Newly accepted","-1":"Rejected"}');

        // can override name conflict
        $sv = SettingValues::make_request($this->u_chair, [
            "has_decision" => 1,
            "decision/1/id" => "2",
            "decision/1/name" => "Really Rejected",
            "decision/1/name_force" => "1",
            "decision/2/id" => "new",
            "decision/2/name" => "Whatever",
            "decision/2/category" => "reject"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode($this->conf->decision_map()), '{"0":"Unspecified","2":"Really Rejected","-1":"Rejected","-2":"Whatever"}');

        // not change name => no need to override conflict
        $sv = SettingValues::make_request($this->u_chair, [
            "has_decision" => 1,
            "decision/1/id" => "2",
            "decision/1/name" => "Really Rejected",
            "decision/2/id" => "-2",
            "decision/2/name" => "Well I dunno"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode($this->conf->decision_map()), '{"0":"Unspecified","2":"Really Rejected","-1":"Rejected","-2":"Well I dunno"}');

        // missing name => error
        $sv = SettingValues::make_request($this->u_chair, [
            "has_decision" => 1,
            "decision/1/id" => "new"
        ]);
        xassert(!$sv->execute());

        // restore default decisions => no database setting
        $sv = SettingValues::make_request($this->u_chair, [
            "has_decision" => 1,
            "decision/1/id" => "new",
            "decision/1/name" => "Accepted",
            "decision/2/id" => "2",
            "decision/2/delete" => "1",
            "decision/3/id" => "-2",
            "decision/3/delete" => "1"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode($this->conf->decision_map()), '{"0":"Unspecified","1":"Accepted","-1":"Rejected"}');
        xassert_eqq($this->conf->setting("outcome_map"), null);
    }

    function test_score_value_class() {
        xassert(!$this->conf->find_review_field("B5"));
        xassert(!$this->conf->find_review_field("B9"));
        xassert(!$this->conf->find_review_field("B10"));
        xassert(!$this->conf->find_review_field("B15"));

        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/name" => "B9",
            "rf/1/id" => "s03",
            "rf/1/choices" => "1. A\n2. B\n3. C\n4. D\n5. E\n6. F\n7. G\n8. H\n9. I",
            "rf/2/name" => "B15",
            "rf/2/id" => "s04",
            "rf/2/choices" => "1. A\n2. B\n3. C\n4. D\n5. E\n6. F\n7. G\n8. H\n9. I\n10. J\n11. K\n12. L\n13. M\n14. N\n15. O",
            "rf/3/name" => "B10",
            "rf/3/id" => "s06",
            "rf/3/choices" => "1. A\n2. B\n3. C\n4. D\n5. E\n6. F\n7. G\n8. H\n9. I\n10. J",
            "rf/4/name" => "B5",
            "rf/4/id" => "s07",
            "rf/4/choices" => "A. A\nB. B\nC. C\nD. D\nE. E"
        ]);
        xassert($sv->execute());

        $rf = $this->conf->find_review_field("B5");
        assert($rf instanceof Score_ReviewField);
        xassert_eqq($rf->value_class(1), "sv sv9");
        xassert_eqq($rf->value_class(2), "sv sv7");
        xassert_eqq($rf->value_class(3), "sv sv5");
        xassert_eqq($rf->value_class(4), "sv sv3");
        xassert_eqq($rf->value_class(5), "sv sv1");
        $rf = $this->conf->find_review_field("B9");
        assert($rf instanceof Score_ReviewField);
        xassert_eqq($rf->value_class(1), "sv sv1");
        xassert_eqq($rf->value_class(2), "sv sv2");
        xassert_eqq($rf->value_class(3), "sv sv3");
        xassert_eqq($rf->value_class(4), "sv sv4");
        xassert_eqq($rf->value_class(5), "sv sv5");
        xassert_eqq($rf->value_class(6), "sv sv6");
        xassert_eqq($rf->value_class(7), "sv sv7");
        xassert_eqq($rf->value_class(8), "sv sv8");
        xassert_eqq($rf->value_class(9), "sv sv9");
        $rf = $this->conf->find_review_field("B15");
        assert($rf instanceof Score_ReviewField);
        xassert_eqq($rf->value_class(1), "sv sv1");
        xassert_eqq($rf->value_class(2), "sv sv2");
        xassert_eqq($rf->value_class(3), "sv sv2");
        xassert_eqq($rf->value_class(4), "sv sv3");
        xassert_eqq($rf->value_class(5), "sv sv3");
        xassert_eqq($rf->value_class(6), "sv sv4");
        xassert_eqq($rf->value_class(7), "sv sv4");
        xassert_eqq($rf->value_class(8), "sv sv5");
        xassert_eqq($rf->value_class(9), "sv sv6");
        xassert_eqq($rf->value_class(10), "sv sv6");
        xassert_eqq($rf->value_class(11), "sv sv7");
        xassert_eqq($rf->value_class(12), "sv sv7");
        xassert_eqq($rf->value_class(13), "sv sv8");
        xassert_eqq($rf->value_class(14), "sv sv8");
        xassert_eqq($rf->value_class(15), "sv sv9");
        $rf = $this->conf->find_review_field("B10");
        assert($rf instanceof Score_ReviewField);
        xassert_eqq($rf->value_class(1), "sv sv1");
        xassert_eqq($rf->value_class(2), "sv sv2");
        xassert_eqq($rf->value_class(3), "sv sv3");
        xassert_eqq($rf->value_class(4), "sv sv4");
        xassert_eqq($rf->value_class(5), "sv sv5");
        xassert_eqq($rf->value_class(6), "sv sv5");
        xassert_eqq($rf->value_class(7), "sv sv6");
        xassert_eqq($rf->value_class(8), "sv sv7");
        xassert_eqq($rf->value_class(9), "sv sv8");
        xassert_eqq($rf->value_class(10), "sv sv9");

        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/id" => "s03",
            "rf/1/colors" => "svr",
            "rf/2/id" => "s04",
            "rf/2/colors" => "svr",
            "rf/3/id" => "s06",
            "rf/3/colors" => "svr",
            "rf/4/id" => "s07",
            "rf/4/colors" => "svr"
        ]);
        xassert($sv->execute());
        $rf = $this->conf->find_review_field("B5");
        assert($rf instanceof Score_ReviewField);
        xassert_eqq($rf->value_class(1), "sv sv1");
        xassert_eqq($rf->value_class(2), "sv sv3");
        xassert_eqq($rf->value_class(3), "sv sv5");
        xassert_eqq($rf->value_class(4), "sv sv7");
        xassert_eqq($rf->value_class(5), "sv sv9");
        $rf = $this->conf->find_review_field("B9");
        assert($rf instanceof Score_ReviewField);
        xassert_eqq($rf->value_class(1), "sv sv9");
        xassert_eqq($rf->value_class(2), "sv sv8");
        xassert_eqq($rf->value_class(3), "sv sv7");
        xassert_eqq($rf->value_class(4), "sv sv6");
        xassert_eqq($rf->value_class(5), "sv sv5");
        xassert_eqq($rf->value_class(6), "sv sv4");
        xassert_eqq($rf->value_class(7), "sv sv3");
        xassert_eqq($rf->value_class(8), "sv sv2");
        xassert_eqq($rf->value_class(9), "sv sv1");
        $rf = $this->conf->find_review_field("B15");
        assert($rf instanceof Score_ReviewField);
        xassert_eqq($rf->value_class(15), "sv sv1");
        xassert_eqq($rf->value_class(14), "sv sv2");
        xassert_eqq($rf->value_class(13), "sv sv2");
        xassert_eqq($rf->value_class(12), "sv sv3");
        xassert_eqq($rf->value_class(11), "sv sv3");
        xassert_eqq($rf->value_class(10), "sv sv4");
        xassert_eqq($rf->value_class(9), "sv sv4");
        xassert_eqq($rf->value_class(8), "sv sv5");
        xassert_eqq($rf->value_class(7), "sv sv6");
        xassert_eqq($rf->value_class(6), "sv sv6");
        xassert_eqq($rf->value_class(5), "sv sv7");
        xassert_eqq($rf->value_class(4), "sv sv7");
        xassert_eqq($rf->value_class(3), "sv sv8");
        xassert_eqq($rf->value_class(2), "sv sv8");
        xassert_eqq($rf->value_class(1), "sv sv9");
        $rf = $this->conf->find_review_field("B10");
        assert($rf instanceof Score_ReviewField);
        xassert_eqq($rf->value_class(10), "sv sv1");
        xassert_eqq($rf->value_class(9), "sv sv2");
        xassert_eqq($rf->value_class(8), "sv sv3");
        xassert_eqq($rf->value_class(7), "sv sv4");
        xassert_eqq($rf->value_class(6), "sv sv5");
        xassert_eqq($rf->value_class(5), "sv sv5");
        xassert_eqq($rf->value_class(4), "sv sv6");
        xassert_eqq($rf->value_class(3), "sv sv7");
        xassert_eqq($rf->value_class(2), "sv sv8");
        xassert_eqq($rf->value_class(1), "sv sv9");

        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/id" => "s03",
            "rf/1/delete" => "1",
            "rf/2/id" => "s04",
            "rf/2/delete" => "1",
            "rf/3/id" => "s06",
            "rf/3/delete" => "1",
            "rf/4/id" => "s07",
            "rf/4/delete" => "1"
        ]);
        xassert($sv->execute());
        xassert(!$this->conf->find_review_field("B5"));
    }

    function test_review_name_required() {
        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/id" => "s90",
            "rf/1/choices" => "1. A\n2. B\n"
        ]);
        xassert(!$sv->execute());
        xassert_neqq(strpos($sv->full_feedback_text(), "Entry required"), false);
    }

    function test_review_rounds() {
        $tn = Conf::$now + 10;

        // reset existing review rounds
        $sv = SettingValues::make_request($this->u_chair, [
            "has_review" => 1,
            "reset" => 1
        ]);
        xassert($sv->execute());
        xassert(!$sv->conf->has_rounds());

        // add a review round
        $sv = SettingValues::make_request($this->u_chair, [
            "has_review" => 1,
            "review/1/id" => "new",
            "review/1/name" => "Butt",
            "review/1/soft" => "@{$tn}",
            "review/1/done" => "@" . ($tn + 10),
            "review/2/id" => "new",
            "review/2/name" => "Fart",
            "review/2/soft" => "@" . ($tn + 1),
            "review/2/done" => "@" . ($tn + 10),
            "review_default_round" => "Fart"
        ]);
        xassert($sv->execute());
        xassert_array_eqq($sv->conf->round_list(), ["", "Butt", "Fart"]);

        // check review_default_round
        $sv = SettingValues::make_request($this->u_chair, [
            "has_review" => 1,
            "review_default_round" => "biglemd"
        ]);
        xassert(!$sv->execute());
    }

    function test_responses() {
        if ($this->conf->setting_data("responses")) {
            $this->conf->save_refresh_setting("responses", null);
            $this->conf->qe("delete from PaperComment where (commentType&?)!=0", CommentInfo::CT_RESPONSE);
        }

        $rrds = $this->conf->response_rounds();
        xassert_eqq(count($rrds), 1);
        xassert_eqq($rrds[0]->id, 1);
        xassert_eqq($rrds[0]->name, "1");
        xassert($rrds[0]->unnamed);
        $t0 = Conf::$now - 1;

        // rename unnamed response round
        $sv = SettingValues::make_request($this->u_chair, [
            "has_response" => 1,
            "response/1/id" => "1",
            "response/1/name" => "Butt",
            "response/1/open" => "@{$t0}",
            "response/1/done" => "@" . ($t0 + 10000),
            "response/1/wordlimit" => "0"
        ]);
        xassert($sv->execute());
        xassert_array_eqq($sv->updated_fields(), ["responses"]);

        $rrds = $this->conf->response_rounds();
        xassert_eqq(count($rrds), 1);
        xassert_eqq($rrds[0]->id, 1);
        xassert_eqq($rrds[0]->name, "Butt");
        xassert_eqq($rrds[0]->open, $t0);
        xassert_eqq($rrds[0]->done, $t0 + 10000);
        xassert(!$rrds[0]->unnamed);

        // add a response
        assert_search_papers($this->u_chair, "has:response", "");
        assert_search_papers($this->u_chair, "has:Buttresponse", "");

        $result = $this->conf->qe("insert into PaperComment (paperId,contactId,timeModified,timeDisplayed,comment,commentType,replyTo,commentRound) values (1,?,?,?,'Hi',?,0,?)", $this->u_chair->contactId, Conf::$now, Conf::$now, CommentInfo::CT_AUTHOR | CommentInfo::CT_RESPONSE, 1);
        $new_commentId = $result->insert_id;

        assert_search_papers($this->u_chair, "has:response", "1");
        assert_search_papers($this->u_chair, "has:Buttresponse", "1");

        // changes ignored if response_active checkbox off
        $sv = SettingValues::make_request($this->u_chair, [
            "has_response_active" => 1,
            "has_response" => 1,
            "response/1/id" => "1",
            "response/1/name" => "ButtJRIOQOIFNINF",
            "response/1/open" => "@{$t0}",
            "response/1/done" => "@" . ($t0 + 10001)
        ]);
        xassert($sv->execute());
        xassert_array_eqq($sv->updated_fields(), []);
        $rrd = $this->conf->response_round_by_id(1);
        xassert_eqq($rrd->name, "Butt");
        xassert_eqq($rrd->open, $t0);
        xassert_eqq($rrd->done, $t0 + 10000);

        // add an unnamed response round
        $sv = SettingValues::make_request($this->u_chair, [
            "has_response" => 1,
            "response/1/id" => "new",
            "response/1/name" => "",
            "response/1/open" => "@{$t0}",
            "response/1/done" => "@" . ($t0 + 10002),
            "response/1/wordlimit" => "0"
        ]);
        xassert($sv->execute());
        xassert_array_eqq($sv->updated_fields(), ["responses"]);

        $rrds = $this->conf->response_rounds();
        xassert_eqq(count($rrds), 2);
        xassert_eqq($rrds[0]->id, 1);
        xassert_eqq($rrds[0]->name, "1");
        xassert_eqq($rrds[0]->open, $t0);
        xassert_eqq($rrds[0]->done, $t0 + 10002);
        xassert($rrds[0]->unnamed);
        xassert_eqq($rrds[1]->id, 2);
        xassert_eqq($rrds[1]->name, "Butt");
        xassert_eqq($rrds[1]->done, $t0 + 10000);
        xassert(!$rrds[1]->unnamed);

        assert_search_papers($this->u_chair, "has:response", "1");
        assert_search_papers($this->u_chair, "has:unnamedresponse", "");
        assert_search_papers($this->u_chair, "has:Buttresponse", "1");

        // switch response round names
        $sv = SettingValues::make_request($this->u_chair, [
            "has_response" => 1,
            "response/1/id" => "1",
            "response/1/name" => "Butt",
            "response/2/id" => "2",
            "response/2/name" => "unnamed"
        ]);
        xassert($sv->execute());
        xassert_array_eqq($sv->updated_fields(), ["responses"]);

        $rrds = $this->conf->response_rounds();
        xassert_eqq(count($rrds), 2);
        xassert_eqq($rrds[0]->id, 1);
        xassert_eqq($rrds[0]->name, "1");
        xassert_eqq($rrds[0]->done, $t0 + 10000);
        xassert($rrds[0]->unnamed);
        xassert_eqq($rrds[1]->id, 2);
        xassert_eqq($rrds[1]->name, "Butt");
        xassert_eqq($rrds[1]->done, $t0 + 10002);
        xassert(!$rrds[1]->unnamed);

        assert_search_papers($this->u_chair, "has:response", "1");
        assert_search_papers($this->u_chair, "has:unnamedresponse", "1");
        assert_search_papers($this->u_chair, "has:Buttresponse", "");

        // response instructions & defaults
        $definstrux = $this->conf->ims()->default_itext("resp_instrux");
        xassert_eqq($rrds[0]->instructions, null);
        xassert_eqq($rrds[0]->instructions($this->conf), $definstrux);
        xassert_eqq($rrds[1]->instructions, null);
        xassert_eqq($rrds[1]->instructions($this->conf), $definstrux);

        $sv = SettingValues::make_request($this->u_chair, [
            "has_response" => 1,
            "response/1/id" => "1",
            "response/1/instructions" => "PANTS",
            "response/2/id" => "2",
            "response/2/instructions" => $definstrux
        ]);
        xassert($sv->execute());
        xassert_array_eqq($sv->updated_fields(), ["responses"]);

        $rrds = $this->conf->response_rounds();
        xassert_eqq($rrds[0]->instructions, "PANTS");
        xassert_eqq($rrds[0]->instructions($this->conf), "PANTS");
        xassert_eqq($rrds[1]->instructions, null);
        xassert_eqq($rrds[1]->instructions($this->conf), $definstrux);

        $sv = SettingValues::make_request($this->u_chair, [
            "has_response" => 1,
            "response/1/id" => "1",
            "response/1/instructions" => $definstrux
        ]);
        xassert($sv->execute());
        xassert_array_eqq($sv->updated_fields(), ["responses"]);

        $rrds = $this->conf->response_rounds();
        xassert_eqq($rrds[0]->instructions, null);
        xassert_eqq($rrds[0]->instructions($this->conf), $definstrux);
        xassert_eqq($rrds[1]->instructions, null);
        xassert_eqq($rrds[1]->instructions($this->conf), $definstrux);

        $this->conf->save_refresh_setting("responses", null);
        $this->conf->qe("delete from PaperComment where paperId=1 and commentId=?", $new_commentId);
    }

    function test_conflictdef() {
        $fr = new FieldRender(FieldRender::CFHTML);
        $this->conf->option_by_id(PaperOption::PCCONFID)->render_description($fr);
        xassert_eqq($fr->value, "Select the PC members who have conflicts of interest with this submission. This includes past advisors and students, people with the same affiliation, and any recent (~2 years) coauthors and collaborators.");
        $this->conf->save_setting("msg.conflictdef", 1, "FART");
        $this->conf->load_settings();
        $this->conf->option_by_id(PaperOption::PCCONFID)->render_description($fr);
        xassert_eqq($fr->value, "Select the PC members who have conflicts of interest with this submission. FART");
        $this->conf->save_setting("msg.conflictdef", null);
        $this->conf->load_settings();
    }

    function test_subform_condition() {
        TestRunner::reset_options();

        // recursive condition not allowed
        $sv = SettingValues::make_request($this->u_chair, [
            "has_sf" => 1,
            "sf/1/name" => "Program",
            "sf/1/id" => "new",
            "sf/1/order" => 100,
            "sf/1/choices" => "Honors\nMBB\nJoint primary\nJoint affiliated\nBasic",
            "sf/1/type" => "radio",
            "sf/1/presence" => "custom",
            "sf/1/condition" => "Program:Honors"
        ]);
        xassert(!$sv->execute());

        // newly-added field conditions can refer to other newly-added fields
        $sv = SettingValues::make_request($this->u_chair, [
            "has_sf" => 1,
            "sf/1/name" => "Program",
            "sf/1/id" => "new",
            "sf/1/order" => 100,
            "sf/1/choices" => "Honors\nMBB\nJoint primary\nJoint affiliated\nBasic",
            "sf/1/type" => "radio",
            "sf/2/name" => "Joint concentration",
            "sf/2/id" => "new",
            "sf/2/order" => 101,
            "sf/2/type" => "text",
            "sf/2/presence" => "custom",
            "sf/2/condition" => "Program:Joint*"
        ]);
        xassert($sv->execute());
        xassert_eqq(trim($sv->full_feedback_text()), "");
    }
}
