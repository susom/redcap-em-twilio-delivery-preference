<?php
namespace Stanford\DeliveryPreference;

// Load trait
require_once "emLoggerTrait.php";

use ExternalModules\ExternalModules;
use REDCap;
use Survey;


class DeliveryPreference extends \ExternalModules\AbstractExternalModule
{
    use emLoggerTrait;

    /**
     * This is the cron task specified in the config.json
     */
    function redcap_save_record($project_id, $record = NULL, $instrument, $event_id, $group_id = NULL, $survey_hash = NULL, $response_id = NULL, $repeat_instance = 1){
        $watch = $this->getProjectSetting("watch-instrument");

        if(empty($watch) || $watch) {

            // Evaluate Logic
            $logic = $this->getProjectSetting("sms-logic");
            if (empty($logic)) {
                $this->emDebug("No logic specified - skipping");
                return;
            }

            $delivery_preference = REDCap::evaluateLogic($logic, $project_id, $record, $event_id , $repeat_instance) ? "SMS_INVITE_WEB" : "EMAIL";
            $this->emDebug("Delivery Preference set to " . $delivery_preference);

            // Get first survey_id
            global $Proj;

            // Get first event_id in the current arm using the given event_id
            $first_event_id = $Proj->getFirstEventIdInArmByEventId($event_id);

            // Get first survey_id
            $first_survey_id = $Proj->firstFormSurveyId;

            if (empty($first_survey_id)) {
                $Proj->loadSurveys();
                $surveys = $Proj->surveys;
                if (!empty($surveys)) {
                    $first_survey_id = key($surveys);
                }
            }

            if (empty($first_survey_id)) {
                $this->emDebug("Unable to identify first_survey_id");
                return;
            }

            // Get a valid participant_id
            $this->emDebug($first_survey_id, $record, $first_event_id);
            list ($participant_id, $hash) = Survey::getFollowupSurveyParticipantIdHash($first_survey_id, $record, $first_event_id);
            $this->emDebug($participant_id,$hash);

            if (empty($participant_id)) {
                $this->emDebug("Unable to find first participant id");
                return;
            }

            $this->emDebug("Participant is $participant_id for event $first_event_id and survey $first_survey_id", "DEBUG");

            // Set this preference on all events/surveys for this record
            $sql1 = "update redcap_surveys_participants set delivery_preference = '".db_escape($delivery_preference)."'" .
                " where participant_id = '".db_escape($participant_id)."'";

            $sql2 = "update redcap_surveys_participants p, redcap_surveys_response r, redcap_surveys s, redcap_surveys t," .
                " redcap_surveys_participants a, redcap_surveys_response b" .
                " set a.delivery_preference = '".db_escape($delivery_preference)."'" .
            " where p.participant_id = '".db_escape($participant_id)."' and r.participant_id = p.participant_id " .
            " and s.survey_id = p.survey_id and s.project_id = t.project_id and t.survey_id = a.survey_id " .
            " and a.participant_id = b.participant_id and b.record = r.record";
            //        Plugin::log($sql1, "DEBUG", "sql1");
            //        Plugin::log($sql2, "DEBUG", "sql2");
            if (db_query($sql1) && db_query($sql2)) {
                // Logging
                $this->emDebug("$sql1;\n$sql2","redcap_surveys_participants","MANAGE",$record,"participant_id = $participant_id","Change participant invitation preference");
                // Return html for delivery preference icon
                $this->emDebug("Updated survey preference for $record to $delivery_preference");
            } else {
                $this->emDebug("Error updating survey preference for $record to $delivery_preference from $instrument", $sql1, $sql1);
            }
        }
    }
}
