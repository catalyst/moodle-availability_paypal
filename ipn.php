<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Listens for Instant Payment Notification from PayPal
 *
 * This script waits for Payment notification from PayPal,
 * then double checks that data by sending it back to PayPal.
 * If PayPal verifies this then sets the activity as completed.
 *
 * @package    availability_paypal
 * @copyright  2010 Eugene Venter
 * @copyright  2015 Daniel Neis
 * @author     Eugene Venter - based on code by others
 * @author     Daniel Neis - based on code by others
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', 1);
define('NO_DEBUG_DISPLAY', 1);

// This file do not require login because paypal service will use to confirm transactions.
// @codingStandardsIgnoreLine
require(__DIR__ . '/../../../config.php');

require_once($CFG->libdir . '/filelib.php');

// PayPal does not like when we return error messages here,
// the custom handler just logs exceptions and stops.
set_exception_handler('availability_paypal_ipn_exception_handler');

// Keep out casual intruders.
if (empty($_POST) or !empty($_GET)) {
    die("Sorry, you can not use the script that way.");
}

// Read all the data from PayPal and get it ready for later;
// we expect only valid UTF-8 encoding, it is the responsibility
// of user to set it up properly in PayPal business account,
// it is documented in docs wiki.
$req = 'cmd=_notify-validate';

foreach ($_POST as $key => $value) {
    $req .= "&$key=" . urlencode($value);
}

$data = new stdclass();
$data->business             = optional_param('business', '', PARAM_TEXT);
$data->receiver_email       = optional_param('receiver_email', '', PARAM_TEXT);
$data->receiver_id          = optional_param('receiver_id', '', PARAM_TEXT);
$data->item_name            = optional_param('item_name', '', PARAM_TEXT);
$data->memo                 = optional_param('memo', '', PARAM_TEXT);
$data->tax                  = optional_param('tax', '', PARAM_TEXT);
$data->option_name1         = optional_param('option_name1', '', PARAM_TEXT);
$data->option_selection1_x  = optional_param('option_selection1_x', '', PARAM_TEXT);
$data->option_name2         = optional_param('option_name2', '', PARAM_TEXT);
$data->option_selection2_x  = optional_param('option_selection2_x', '', PARAM_TEXT);
$data->payment_status       = optional_param('payment_status', '', PARAM_TEXT);
$data->pending_reason       = optional_param('pending_reason', '', PARAM_TEXT);
$data->reason_code          = optional_param('reason_code', '', PARAM_TEXT);
$data->txn_id               = optional_param('txn_id', '', PARAM_TEXT);
$data->parent_txn_id        = optional_param('parent_txn_id', '', PARAM_TEXT);
$data->payment_type         = optional_param('payment_type', '', PARAM_TEXT);
$data->payment_gross        = optional_param('mc_gross', '', PARAM_TEXT);
$data->payment_currency     = optional_param('mc_currency', '', PARAM_TEXT);

$custom = optional_param('custom', '', PARAM_TEXT);
$custom = explode('-', $custom);

$data->userid = (int) ($custom[0] ?? -1);
$data->contextid = (int) ($custom[1] ?? -1);
$data->sectionid = (int) ($custom[2] ?? -1);

$data->timeupdated = time();

if (!$user = $DB->get_record("user", array("id" => $data->userid))) {
    $PAGE->set_context(context_system::instance());
    availability_paypal_message_error("Not a valid user id", $data);
    die;
}

if (!$context = context::instance_by_id($data->contextid, IGNORE_MISSING)) {
    $PAGE->set_context(context_system::instance());
    availability_paypal_message_error("Not a valid context id", $data);
    die;
}

$PAGE->set_context($context);

if ($context instanceof context_module) {
    $availability = $DB->get_field('course_modules', 'availability', ['id' => $context->instanceid], MUST_EXIST);
} else {
    $availability = $DB->get_field('course_sections', 'availability', ['id' => $data->sectionid], MUST_EXIST);
}

$availability = json_decode($availability);

$paypal = null;

if ($availability) {
    // There can be multiple conditions specified. Find the first of the type "paypal".
    // TODO: Support more than one paypal condition specified.
    foreach ($availability->c as $condition) {
        if ($condition->type == 'paypal') {
            $paypal = $condition;
            break;
        }
    }
}

if (empty($paypal)) {
    availability_paypal_message_error("PayPal condition not found while processing incoming IPN", $data);
    die();
}

// Open a connection back to PayPal to validate the data.
$paypaladdr = empty($CFG->usepaypalsandbox) ? 'ipnpb.paypal.com' : 'ipnpb.sandbox.paypal.com';
$c = new curl();
$options = array(
    'returntransfer' => true,
    'httpheader' => array('application/x-www-form-urlencoded', "Host: $paypaladdr"),
    'timeout' => 30,
    'CURLOPT_HTTP_VERSION' => CURL_HTTP_VERSION_1_1,
);
$location = "https://{$paypaladdr}/cgi-bin/webscr";
$result = $c->post($location, $req, $options);

if ($c->get_errno()) {
    availability_paypal_message_error("Could not access paypal.com to verify payment", $data);
    die;
}

// Connection is OK, so now we post the data to validate it.

// Now read the response and check if everything is OK.

if (strlen($result) > 0) {
    if (strcmp($result, "VERIFIED") == 0) {          // VALID PAYMENT!

        $DB->insert_record("availability_paypal_tnx", $data);

        // Check the payment_status and payment_reason.

        // If status is not completed, just tell admin, transaction will be saved later.
        if ($data->payment_status != "Completed" and $data->payment_status != "Pending") {
            availability_paypal_message_error("Status not completed or pending. User payment status updated", $data);
        }

        // If currency is incorrectly set then someone maybe trying to cheat the system.
        if ($data->payment_currency != $paypal->currency) {
            $str = "Currency does not match course settings, received: " . $data->payment_currency;
            availability_paypal_message_error($str, $data);
            die;
        }

        // If cost is incorrectly set then someone maybe trying to cheat the system.
        if ($data->payment_gross != $paypal->cost) {
            $str = "Payment gross does not match course settings, received: " . $data->payment_gross;
            availability_paypal_message_error($str, $data);
            die;
        }

        // If status is pending and reason is other than echeck,
        // then we are on hold until further notice.
        // Email user to let them know. Email admin.
        if ($data->payment_status == "Pending" and $data->pending_reason != "echeck") {

            $eventdata = new \core\message\message();
            $eventdata->component         = 'availability_paypal';
            $eventdata->name              = 'payment_pending';
            $eventdata->userfrom          = get_admin();
            $eventdata->userto            = $user;
            $eventdata->subject           = get_string("paypalpaymentpendingsubject", 'availability_paypal');
            $eventdata->fullmessage       = get_string('paypalpaymentpendingmessage', 'availability_paypal');
            $eventdata->fullmessageformat = FORMAT_PLAIN;
            $eventdata->fullmessagehtml   = '';
            $eventdata->smallmessage      = '';
            message_send($eventdata);
        }

        // If our status is not completed or not pending on an echeck clearance then ignore and die.
        // This check is redundant at present but may be useful if paypal extend the return codes in the future.
        if (! ( $data->payment_status == "Completed" or
               ($data->payment_status == "Pending" and $data->pending_reason == "echeck") ) ) {
            die;
        }

        // At this point we only proceed with a status of completed or pending with a reason of echeck.

        // Make sure this transaction doesn't exist already.
        if ($existing = $DB->get_record("availability_paypal_tnx", array("txn_id" => $data->txn_id))) {
            availability_paypal_message_error("Transaction $data->txn_id is being repeated!", $data);
            die;
        }

    } else if (strcmp ($result, "INVALID") == 0) { // ERROR.
        $DB->insert_record("availability_paypal_tnx", $data, false);
        availability_paypal_message_error("Received an invalid payment notification!! (Fake payment?)", $data);
    }
}

/**
 * Sends message to admin about error
 *
 * @param string $subject
 * @param stdClass $data
 */
function availability_paypal_message_error($subject, $data) {

    $userfrom = core_user::get_noreply_user();
    $recipients = get_users_by_capability(context_system::instance(), 'availability/paypal:receivenotifications');

    if (empty($recipients)) {
        // Make sure that someone is notified.
        $recipients = get_admins();
    }

    $site = get_site();

    $text = "$site->fullname: PayPal transaction problem: {$subject}\n\n";
    $text .= "Transaction data:\n";

    if ($data) {
        foreach ($data as $key => $value) {
            $text .= "* {$key} => {$value}\n";
        }
    }

    foreach ($recipients as $recipient) {
        $message = new \core\message\message();
        $message->component = 'availability_paypal';
        $message->name = 'payment_error';
        $message->userfrom = core_user::get_noreply_user();
        $message->userto = $recipient;
        $message->subject = "PayPal ERROR: " . $subject;
        $message->fullmessage = $text;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = text_to_html($text);
        $message->smallmessage = $subject;
        message_send($message);
    }
}

/**
 * Silent exception handler.
 *
 * @param Exception $ex
 * @return void - does not return. Terminates execution!
 */
function availability_paypal_ipn_exception_handler($ex) {
    $info = get_exception_info($ex);

    $logerrmsg = "availability_paypal IPN exception handler: ".$info->message;
    if (debugging('', DEBUG_NORMAL)) {
        $logerrmsg .= ' Debug: '.$info->debuginfo."\n".format_backtrace($info->backtrace, true);
    }
    debugging($logerrmsg);
    exit(0);
}
