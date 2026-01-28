<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace availability_paypal\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\userlist;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for the PayPal availability condition.
 *
 * @package     availability_paypal
 * @copyright   2015 Daniel Neis Araujo <danielneis@gmail.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\subsystem\provider {
    /**
     * Return the metadata about data stored by this plugin.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $fields = [
            'id' => 'privacy:metadata:availability_paypal_tnx:id',
            'business' => 'privacy:metadata:availability_paypal_tnx:business',
            'receiver_email' => 'privacy:metadata:availability_paypal_tnx:receiver_email',
            'receiver_id' => 'privacy:metadata:availability_paypal_tnx:receiver_id',
            'item_name' => 'privacy:metadata:availability_paypal_tnx:item_name',
            'userid' => 'privacy:metadata:availability_paypal_tnx:userid',
            'contextid' => 'privacy:metadata:availability_paypal_tnx:contextid',
            'sectionid' => 'privacy:metadata:availability_paypal_tnx:sectionid',
            'memo' => 'privacy:metadata:availability_paypal_tnx:memo',
            'tax' => 'privacy:metadata:availability_paypal_tnx:tax',
            'option_name1' => 'privacy:metadata:availability_paypal_tnx:option_name1',
            'option_selection1_x' => 'privacy:metadata:availability_paypal_tnx:option_selection1_x',
            'option_name2' => 'privacy:metadata:availability_paypal_tnx:option_name2',
            'option_selection2_x' => 'privacy:metadata:availability_paypal_tnx:option_selection2_x',
            'payment_status' => 'privacy:metadata:availability_paypal_tnx:payment_status',
            'pending_reason' => 'privacy:metadata:availability_paypal_tnx:pending_reason',
            'reason_code' => 'privacy:metadata:availability_paypal_tnx:reason_code',
            'txn_id' => 'privacy:metadata:availability_paypal_tnx:txn_id',
            'parent_txn_id' => 'privacy:metadata:availability_paypal_tnx:parent_txn_id',
            'payment_type' => 'privacy:metadata:availability_paypal_tnx:payment_type',
            'timeupdated' => 'privacy:metadata:availability_paypal_tnx:timeupdated',
        ];

        $collection->add_database_table('availability_paypal_tnx', $fields, 'privacy:metadata:availability_paypal_tnx');

        return $collection;
    }

    /**
     * Return all contexts that contain user information for the given user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_from_sql(
            "SELECT DISTINCT ctx.id
                  FROM {context} ctx
                  JOIN {availability_paypal_tnx} t ON t.contextid = ctx.id
                 WHERE t.userid = :userid",
            ['userid' => $userid]
        );

        return $contextlist;
    }

    /**
     * Get the users in a context.
     *
     * @param userlist $userlist
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();

        $sql = "SELECT DISTINCT userid
                  FROM {availability_paypal_tnx}
                 WHERE contextid = :contextid";
        $params = ['contextid' => $context->id];

        $userids = $DB->get_fieldset_sql($sql, $params);
        foreach ($userids as $uid) {
            $userlist->add_user($uid);
        }
    }

    /**
     * Export user data for approved contexts.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        $contexts = $contextlist->get_contexts();

        foreach ($contexts as $context) {
            $records = $DB->get_records('availability_paypal_tnx', ['userid' => $userid, 'contextid' => $context->id]);
            if (empty($records)) {
                continue;
            }

            $transactions = [];
            foreach ($records as $r) {
                $transactions[] = (object) [
                    'id' => (int)$r->id,
                    'business' => $r->business,
                    'receiver_email' => $r->receiver_email,
                    'receiver_id' => $r->receiver_id,
                    'item_name' => $r->item_name,
                    'sectionid' => (int)$r->sectionid,
                    'memo' => $r->memo,
                    'tax' => $r->tax,
                    'option_name1' => $r->option_name1,
                    'option_selection1_x' => $r->option_selection1_x,
                    'option_name2' => $r->option_name2,
                    'option_selection2_x' => $r->option_selection2_x,
                    'payment_status' => $r->payment_status,
                    'pending_reason' => $r->pending_reason,
                    'reason_code' => $r->reason_code,
                    'txn_id' => $r->txn_id,
                    'parent_txn_id' => $r->parent_txn_id,
                    'payment_type' => $r->payment_type,
                    'timeupdated' => (int)$r->timeupdated,
                ];
            }

            $exportdata = (object) ['transactions' => $transactions];

            writer::with_context($context)->export_data(
                ['availability_paypal_tnx' => 'privacy:metadata:availability_paypal_tnx'],
                $exportdata
            );
        }
    }

    /**
     * Delete all user data for all users in the given context.
     * If the context is a user context, delete data for that user.
     *
     * @param \context $context
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel == CONTEXT_USER) {
            $userid = $context->instanceid;
            $DB->delete_records('availability_paypal_tnx', ['userid' => $userid]);
        }
    }

    /**
     * Delete user data for the specified user in the approved contexts.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        $contexts = $contextlist->get_contexts();

        foreach ($contexts as $context) {
            $DB->delete_records('availability_paypal_tnx', ['userid' => $userid, 'contextid' => $context->id]);
        }
    }

    /**
     * Delete data for a list of users in a single context.
     *
     * @param approved_userlist $userlist
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        // Sanitize ints.
        $userids = array_map('intval', $userids);

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['contextid'] = $context->id;
        $DB->delete_records_select('availability_paypal_tnx', "contextid = :contextid AND userid $insql", $params);
    }
}
