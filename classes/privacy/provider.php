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
 * Privacy Subsystem implementation for plagiarism_turnitinsim.
 *
 * @package   plagiarism_turnitinsim
 * @copyright 2018 Turnitin
 * @author    John McGettrick <jmcgettrick@turnitin.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_turnitinsim\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy Subsystem implementation for plagiarism_turnitinsim.
 */
class provider implements
    // This plugin does store personal user data.
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_plagiarism\privacy\plagiarism_provider {

    // This trait must be included to provide the relevant polyfill for the metadata provider.
    use \core_privacy\local\legacy_polyfill;

    // This trait must be included to provide the relevant polyfill for the plagirism provider.
    use \core_plagiarism\privacy\legacy_polyfill;

    /**
     * Return the fields which contain personal data.
     *
     * @param collection $collection A reference to the collection to use to store the metadata.
     * @return collection The updated collection of metadata items.
     */
    public static function _get_metadata(collection $collection) {
        $collection->add_database_table(
            'plagiarism_turnitinsim_sub',
            [
                'userid' => 'privacy:metadata:plagiarism_turnitinsim_sub:userid',
                'turnitinid' => 'privacy:metadata:plagiarism_turnitinsim_sub:turnitinid',
                'identifier' => 'privacy:metadata:plagiarism_turnitinsim_sub:identifier',
                'itemid' => 'privacy:metadata:plagiarism_turnitinsim_sub:itemid',
                'submittedtime' => 'privacy:metadata:plagiarism_turnitinsim_sub:submittedtime',
                'overallscore' => 'privacy:metadata:plagiarism_turnitinsim_sub:overallscore'
            ],
            'privacy:metadata:plagiarism_turnitinsim_sub'
        );

        $collection->add_database_table(
            'plagiarism_turnitinsim_users',
            [
                'userid' => 'privacy:metadata:plagiarism_turnitinsim_users:userid',
                'turnitinid' => 'privacy:metadata:plagiarism_turnitinsim_users:turnitinid',
                'lasteulaaccepted' => 'privacy:metadata:plagiarism_turnitinsim_users:lasteulaaccepted',
                'lasteulaacceptedtime' => 'privacy:metadata:plagiarism_turnitinsim_users:lasteulaacceptedtime',
                'lasteulaacceptedlang' => 'privacy:metadata:plagiarism_turnitinsim_users:lasteulaacceptedlang'
            ],
            'privacy:metadata:plagiarism_turnitinsim_users'
        );

        $collection->link_external_location('plagiarism_turnitinsim_client', [
            'firstname' => 'privacy:metadata:plagiarism_turnitinsim_client:firstname',
            'lastname' => 'privacy:metadata:plagiarism_turnitinsim_client:lastname',
            'submission_title' => 'privacy:metadata:plagiarism_turnitinsim_client:submission_title',
            'submission_filename' => 'privacy:metadata:plagiarism_turnitinsim_client:submission_filename',
            'submission_content' => 'privacy:metadata:plagiarism_turnitinsim_client:submission_content',
        ], 'privacy:metadata:plagiarism_turnitinsim_client');

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid the userid.
     * @return contextlist the list of contexts containing user info for the user.
     */
    public static function _get_contexts_for_userid($userid) {

        $params = ['modulename' => 'assign',
            'contextlevel' => CONTEXT_MODULE,
            'userid' => $userid];

        $sql = "SELECT ctx.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON cm.module = m.id AND m.name = :modulename
                  JOIN {assign} a ON cm.instance = a.id
                  JOIN {context} ctx ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
             LEFT JOIN {plagiarism_turnitinsim_sub} ts ON ts.cm = cm.instance
                 WHERE ts.userid = :userid";

        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }


    /**
     * Export all plagiarism data from each plagiarism plugin for the specified userid and context.
     *
     * @param   int         $userid The user to export.
     * @param   \context    $context The context to export.
     * @param   array       $subcontext The subcontext within the context to export this information to.
     * @param   array       $linkarray The weird and wonderful link array used to display information for a specific item
     */
    public static function _export_plagiarism_user_data($userid, \context $context, array $subcontext, array $linkarray) {
        global $DB;

        if (empty($userid)) {
            return;
        }

        $user = $DB->get_record('user', array('id' => $userid));

        $params = ['userid' => $user->id];

        $sql = "SELECT cm,
                userid,
                turnitinid,
                identifier,
                itemid,
                submittedtime,
                overallscore
                  FROM {plagiarism_turnitinsim_sub}
                 WHERE userid = :userid";
        $submissions = $DB->get_records_sql($sql, $params);

        foreach ($submissions as $submission) {
            $context = \context_module::instance($submission->cm);
            self::_export_plagiarism_turnitinsim_data_for_user((array)$submission, $context, $user);
        }
    }

    /**
     * Export the supplied personal data for a single activity, along with any generic data or area files.
     *
     * @param array $submissiondata the personal data to export.
     * @param \context_module $context the module context.
     * @param \stdClass $user the user record
     */
    protected static function _export_plagiarism_turnitinsim_data_for_user(array $submissiondata,
         \context_module $context, \stdClass $user) {
        // Fetch the generic module data.
        $contextdata = helper::get_context_data($context, $user);

        // Merge with module data and write it.
        $contextdata = (object)array_merge((array)$contextdata, $submissiondata);
        writer::with_context($context)->export_data([], $contextdata);

        // Write generic module intro files.
        helper::export_context_files($context, $user);
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context the context to delete in.
     */
    public static function _delete_plagiarism_for_context(\context $context) {
        global $DB;

        if (empty($context)) {
            return;
        }

        if (!$context instanceof \context_module) {
            return;
        }

        // Delete all submissions.
        $DB->delete_records('plagiarism_turnitinsim_sub', ['cm' => $context->instanceid]);
    }

    /**
     * Delete all user information for the provided user and context.
     *
     * @param  int      $userid    The user to delete
     * @param  \context $context   The context to refine the deletion.
     */
    public static function _delete_plagiarism_for_user($userid, \context $context) {
        global $DB;

        $DB->delete_records('plagiarism_turnitinsim_sub', ['userid' => $userid]);
        $DB->delete_records('plagiarism_turnitinsim_users', ['userid' => $userid]);
    }

    /**
     * Get a list of users who have data within a context.
     *
     * @param   userlist $userlist The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
      $context = $userlist->get_context();

      if ($context->contextlevel != CONTEXT_MODULE) {
          return;
      }

      $sql = "SELECT pts.userid
                FROM {plagiarism_turnitinsim_sub} pts
                JOIN {course_modules} c 
                  ON pts.cm = c.id
                JOIN {modules} m
                  ON m.id = c.module AND m.name = :modname
               WHERE c.id = :cmid";

      $params = [
          'modname' => 'plagiarism_turnitinsim',
          'cmid' => $context->instanceid
      ];

      $userlist->add_from_sql('userid', $sql, $params);
    }

    /**
     * Delete data for multiple users within a single context.
     *
     * @param   approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
      global $DB;

      $context = $userlist->get_context();

      if ($context->contextlevel != CONTEXT_MODULE) {
          return;
      }

      $userids = $userlist->get_userids();

      list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

      $sql1 = "SELECT pts.id
                 FROM {plagiarism_turnitinsim_sub} pts
                 JOIN {course_modules} c 
                   ON pts.cm = c.id
                 JOIN {modules} m 
                   ON m.id = c.module AND m.name = :modname
                WHERE pts.userid $insql
                  AND c.id = :cmid";

      $params = [
          'modname' => 'plagiarism_turnitinsim',
          'cmid' => $context->instanceid
      ];

      $params = array_merge($params, $inparams);

      $attempt = $DB->get_fieldset_sql($sql1, $params);

      $DB->delete_records_list('plagiarism_turnitinsim_sub', 'id', array_values($attempt));
    }
}