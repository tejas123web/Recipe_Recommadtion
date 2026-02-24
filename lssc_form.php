<?php
/*
Template Name: u_lssc_form
*/

date_default_timezone_set('Asia/Kolkata');

if (!defined('ABSPATH')) exit;

// *** get_header() is called AFTER all POST handlers to allow wp_redirect() ***

if (!is_user_logged_in()) {
    get_header();
    echo "<p style='text-align:center;color:#dc3545;padding:40px;font-size:16px;'>Please login to access this system.</p>";
    get_footer(); exit;
}

global $wpdb;
$wpdb->show_errors();

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', WP_CONTENT_DIR . '/debug.log');

$user  = wp_get_current_user();
$table = $wpdb->prefix . 'lssc_form';

$emp = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}employee_table_2 WHERE email = %s", $user->user_email
));
if (!$emp) {
    get_header();
    echo "<p style='color:#dc3545;text-align:center;padding:40px;font-size:16px;'>Employee record not found.</p>";
    get_footer(); exit;
}

// ========== ROLE DETERMINATION ==========
$user_role = 'none';
$funcdesg  = strtoupper(trim($emp->funcdesg ?? ''));
$desgn     = strtoupper(trim($emp->desgn ?? ''));
$section   = strtoupper(trim($emp->sectionfullname ?? ''));
$division  = strtoupper(trim($emp->divisionfullname ?? ''));

// ── QA ENGINEER: checked FIRST — anyone in the QA & T&E division
if (strpos($division, 'QUALITY ASSURANCE AND TEST EVALUATION') !== false) {
    $user_role = 'qa_engineer';
}

// ── MANAGER / DIRECTOR
if ($user_role === 'none') {
    foreach (['MANAGER','DIRECTOR'] as $kw) {
        if (strpos($funcdesg, $kw) !== false) { $user_role = 'manager'; break; }
    }
}

// ── INDENTER (Scientist/Engineer grades)
if ($user_role === 'none') {
    foreach (['SCIENTIST/ENGINEER-SG','SCIENTIST/ENGINEER-SC','SCIENTIST/ENGINEER-SD',
              'SCIENTIST/ENGINEER-SF','SCIENTIST/ENGINEER-SE','SCIENTIST/ENGINEER-G'] as $kw) {
        if ($desgn === $kw || strpos($desgn, $kw) === 0) { $user_role = 'indenter'; break; }
    }
}

// ── LSSC STAFF (technicians in LARGE SPACE section)
if ($user_role === 'none') {
    $is_lssc_sec  = strpos($section, 'LARGE SPACE') !== false || strpos($division, 'LARGE SPACE') !== false;
    $lssc_desgs   = ['TECHNICAL ASSISTANT','SR. TECHNICAL ASST.-A','SR. TECHNICAL ASST',
        'TECHNICIAN-F','TECHNICIAN-G','TECHNICIAN-D','TECHNICIAN-B','TECHNICIAN',
        'TECHNICAL OFFICER-C','TECHNICAL OFFICER-D','TECHNICAL OFFICER-E','TECHNICAL OFFICER',
        'ASSISTANT ENGINEER','JUNIOR ENGINEER'];
    $is_lssc_desg = false;
    foreach ($lssc_desgs as $d) { if (strpos($desgn, $d) !== false) { $is_lssc_desg = true; break; } }
    if ($is_lssc_sec && $is_lssc_desg) $user_role = 'lssc';
}

if ($user_role === 'none') {
    get_header();
    echo "<div style='text-align:center;padding:60px;'><h2>Access Denied</h2><p>Your designation does not have access to this system.</p></div>";
    get_footer(); exit;
}

// ========== TABLE CREATION ==========
if (!$wpdb->get_var("SHOW TABLES LIKE '$table'")) {
    $wpdb->query("CREATE TABLE $table (
        id INT AUTO_INCREMENT PRIMARY KEY,
        test_requisition_no VARCHAR(50) UNIQUE NOT NULL,
        submission_date DATETIME DEFAULT CURRENT_TIMESTAMP,
        satellite_name VARCHAR(255),
        test_type VARCHAR(100),
        project_program VARCHAR(255),
        test_required_on DATE,
        sub_name VARCHAR(100),
        sub_stno VARCHAR(50),
        sub_email VARCHAR(100),
        sub_section VARCHAR(100),
        sub_division VARCHAR(100),
        sub_designation VARCHAR(100),
        sub_phone VARCHAR(20),
        thermal_power INT DEFAULT 0,
        thermal_thermocouples INT DEFAULT 0,
        ground_dc_signal INT DEFAULT 0,
        ground_dc_signal_comments TEXT,
        ground_signal_power INT DEFAULT 0,
        ground_signal_power_comments TEXT,
        thermal_power_comments TEXT,
        thermal_thermocouples_comments TEXT,
        rf_connector_type VARCHAR(100),
        rf_connector_channels INT DEFAULT 0,
        rf_connector_comments TEXT,
        special_requirements LONGTEXT,
        user_id BIGINT,
        status VARCHAR(50) DEFAULT 'draft_indenter',
        indenter_draft_saved_at DATETIME,
        indenter_draft_saved_by VARCHAR(100),
        manager_id BIGINT,
        manager_comment LONGTEXT,
        reviewed_by VARCHAR(100),
        risk_assessed VARCHAR(50),
        rpn VARCHAR(50),
        risk_record VARCHAR(50),
        approval_date DATETIME,
        env_vacuum VARCHAR(100),
        env_shroud_temp VARCHAR(100),
        env_solar_beam VARCHAR(100),
        env_eclipse VARCHAR(100),
        env_motion_tilt VARCHAR(100),
        env_motion_spin VARCHAR(100),
        env_motion_speed VARCHAR(100),
        env_mechanical VARCHAR(255),
        env_special_req VARCHAR(255),
        env_key_char VARCHAR(100),
        requisition_received_date DATE,
        risk_assessed_lssc VARCHAR(50),
        rpn_lssc VARCHAR(50),
        risk_form_filled VARCHAR(50),
        special_processes VARCHAR(50),
        test_received_reviewed VARCHAR(50),
        test_object_accepted VARCHAR(50),
        test_accepted_by VARCHAR(100),
        test_started_datetime DATETIME,
        test_completed_datetime DATETIME,
        test_duration VARCHAR(100),
        test_on_time VARCHAR(50),
        specimen_collected_by_name VARCHAR(100),
        specimen_collected_by_sig VARCHAR(255),
        verification_closed_by_name VARCHAR(100),
        verification_closed_by_sig VARCHAR(255),
        completion_date DATETIME,
        draft_saved_at DATETIME,
        draft_saved_by VARCHAR(100),
        chamber_used VARCHAR(100),
        test_type_etf VARCHAR(100),
        test_code VARCHAR(50),
        INDEX idx_test_requisition_no (test_requisition_no),
        INDEX idx_user_id (user_id),
        INDEX idx_status (status),
        INDEX idx_submission_date (submission_date)
    )");
}

// ========== RUNTIME DB MIGRATION (for existing tables) ==========
$existing_cols = $wpdb->get_col("DESCRIBE {$table}", 0);
$new_cols = [
    'indenter_draft_saved_at' => 'DATETIME',
    'indenter_draft_saved_by' => 'VARCHAR(100)',
    'thermal_power_comments'  => 'TEXT',
    'thermal_thermocouples_comments' => 'TEXT',
    'ground_dc_signal_comments'      => 'TEXT',
    'ground_signal_power_comments'   => 'TEXT',
    'rf_connector_channels' => 'INT DEFAULT 0',
    'rf_connector_comments' => 'TEXT',
    'qa_exists'         => "VARCHAR(10) DEFAULT 'no'",
    'qa_name'           => 'VARCHAR(100)',
    'qa_stno'           => 'VARCHAR(50)',
    'qa_section'        => 'VARCHAR(255)',
    'qa_phone'          => 'VARCHAR(50)',
    'qa_review_date'    => 'DATETIME',
    'qa_reviewer_name'  => 'VARCHAR(100)',
    'qa_remarks'        => 'LONGTEXT',
    'qa_decision'       => "VARCHAR(20) DEFAULT ''",
    // Manager review action columns (controlled approval workflow)
    'manager_action'                => "VARCHAR(20) DEFAULT 'pending'",
    'manager_final_comment'         => 'LONGTEXT',
    'recheck_sent_to_indenter_date' => 'DATETIME',
    'env_vacuum_mgr_comment'        => 'TEXT',
    'env_shroud_temp_mgr_comment'   => 'TEXT',
    'env_solar_beam_mgr_comment'    => 'TEXT',
    'env_eclipse_mgr_comment'       => 'TEXT',
    'env_motion_tilt_mgr_comment'   => 'TEXT',
    'env_motion_spin_mgr_comment'   => 'TEXT',
    'env_motion_speed_mgr_comment'  => 'TEXT',
    'env_mechanical_mgr_comment'    => 'TEXT',
    'env_special_req_mgr_comment'   => 'TEXT',
    'env_key_char_mgr_comment'      => 'TEXT',
];
if (is_array($existing_cols)) {
    foreach ($new_cols as $col => $definition) {
        if (!in_array($col, $existing_cols)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$col} {$definition}");
        }
    }
    // Update default status if needed (allow draft_indenter)
    if (in_array('status', $existing_cols)) {
        $wpdb->query("ALTER TABLE {$table} MODIFY COLUMN status VARCHAR(50) DEFAULT 'draft_indenter'");
    }
}

// ========== HELPERS ==========

// NOTE: fetch_employee AJAX handler is registered in functions.php

function lssc_notify_qa($wpdb, $tr_no, $name) {
    $qa_staff = $wpdb->get_results("SELECT DISTINCT email FROM {$wpdb->prefix}employee_table_2 WHERE funcdesg LIKE '%QA ENGINEER%' AND email IS NOT NULL AND email!=''");
    foreach ($qa_staff as $q) wp_mail($q->email, "LSSC Request Pending QA Review - $tr_no", "Submitted by $name. Please review.");
}
function lssc_notify_managers($wpdb, $tr_no, $name) {
    $mgrs = $wpdb->get_results("SELECT DISTINCT email FROM {$wpdb->prefix}employee_table_2 WHERE funcdesg LIKE '%MANAGER%' AND email IS NOT NULL AND email!=''");
    foreach ($mgrs as $m) wp_mail($m->email, "New LSSC Request - $tr_no", "Submitted by $name\nTR No: $tr_no");
}
function lssc_notify_indenter($form) {
    $u = get_userdata($form->user_id);
    if ($u) wp_mail($u->user_email, "LSSC Request {$form->test_requisition_no} - {$form->status}", "Status: {$form->status}");
}
function lssc_notify_lssc($wpdb, $form) {
    $lssc = $wpdb->get_results("SELECT DISTINCT email FROM {$wpdb->prefix}employee_table_2 WHERE sectionfullname LIKE '%LARGE SPACE%' AND email IS NOT NULL AND email!=''");
    foreach ($lssc as $e) wp_mail($e->email, "LSSC Request Approved - {$form->test_requisition_no}", "TR: {$form->test_requisition_no}");
}

// ========== POST HANDLERS ==========

/* ---------- INDENTER SAVE DRAFT ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_indenter_draft'])) {
    if ($user_role!=='indenter' && $user_role!=='manager') wp_die('Unauthorized');
    if (!wp_verify_nonce($_POST['lssc_nonce'],'lssc_action')) wp_die('Security check failed');

    $draft_id = intval($_POST['draft_id'] ?? 0);
    $draft_data = [
        'submission_date'        => date('Y-m-d H:i:s'),
        'satellite_name'         => sanitize_text_field($_POST['satellite_name'] ?? ''),
        'test_type'              => sanitize_text_field($_POST['test_type'] ?? ''),
        'project_program'        => sanitize_text_field($_POST['project_program'] ?? ''),
        'test_required_on'       => sanitize_text_field($_POST['test_required_on'] ?? ''),
        'sub_name'               => sanitize_text_field($_POST['sub_name'] ?? ''),
        'sub_stno'               => sanitize_text_field($_POST['sub_stno'] ?? ''),
        'sub_email'              => sanitize_text_field($_POST['sub_email'] ?? ''),
        'sub_section'            => sanitize_text_field($_POST['sub_section'] ?? ''),
        'sub_division'           => sanitize_text_field($_POST['sub_division'] ?? ''),
        'sub_designation'        => sanitize_text_field($_POST['sub_designation'] ?? ''),
        'sub_phone'              => sanitize_text_field($_POST['sub_phone'] ?? ''),
        'thermal_power'          => intval($_POST['thermal_power'] ?? 0),
        'thermal_thermocouples'  => intval($_POST['thermal_thermocouples'] ?? 0),
        'ground_dc_signal'       => intval($_POST['ground_dc_signal'] ?? 0),
        'ground_signal_power'    => intval($_POST['ground_signal_power'] ?? 0),
        'rf_connector_type'           => sanitize_text_field($_POST['rf_connector_type'] ?? ''),
        'rf_connector_channels'       => intval($_POST['rf_connector_channels'] ?? 0),
        'rf_connector_comments'       => sanitize_text_field($_POST['rf_connector_comments'] ?? ''),
        'thermal_power_comments'      => sanitize_text_field($_POST['thermal_power_comments'] ?? ''),
        'thermal_thermocouples_comments' => sanitize_text_field($_POST['thermal_thermocouples_comments'] ?? ''),
        'ground_dc_signal_comments'   => sanitize_text_field($_POST['ground_dc_signal_comments'] ?? ''),
        'ground_signal_power_comments'=> sanitize_text_field($_POST['ground_signal_power_comments'] ?? ''),
        'special_requirements'        => sanitize_textarea_field($_POST['special_requirements'] ?? ''),
        'qa_exists'              => sanitize_text_field($_POST['qa_exists'] ?? 'no'),
        'qa_name'                => sanitize_text_field($_POST['qa_name'] ?? ''),
        'qa_stno'                => sanitize_text_field($_POST['qa_stno'] ?? ''),
        'qa_section'             => sanitize_text_field($_POST['qa_section'] ?? ''),
        'qa_phone'               => sanitize_text_field($_POST['qa_phone'] ?? ''),
        'user_id'                => $user->ID,
        'status'                 => 'draft_indenter',
        'indenter_draft_saved_at'=> date('Y-m-d H:i:s'),
        'indenter_draft_saved_by'=> $emp->name,
    ];

    if ($draft_id > 0) {
        // Allow updating draft_indenter OR qa_rejected records
        $wpdb->update($table, $draft_data, ['id' => $draft_id, 'user_id' => $user->ID]);
        if ($wpdb->last_error) error_log('LSSC draft update error: ' . $wpdb->last_error);
    } else {
        $tr_placeholder = 'DRAFT-' . $user->ID . '-' . uniqid();
        $insert_data = array_merge(['test_requisition_no' => $tr_placeholder], $draft_data);
        $wpdb->insert($table, $insert_data);
        if ($wpdb->last_error) error_log('LSSC draft insert error: ' . $wpdb->last_error);
    }
    // Redirect to dashboard with success message via URL param
    wp_redirect(add_query_arg('lssc_msg', 'draft_saved', remove_query_arg(['action','view_id','resume_draft','mgr_action','complete_id','prog_id'], get_permalink())));
    exit;
}

/* ---------- INDENTER/MANAGER SUBMIT FOR APPROVAL ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['submit_request'])) {
    if ($user_role!=='indenter' && $user_role!=='manager') wp_die('Unauthorized');
    if (!wp_verify_nonce($_POST['lssc_nonce'],'lssc_action')) wp_die('Security check failed');

    $errors = [];
    if (empty($_POST['satellite_name'])) $errors[]='Satellite/Test Object name required';
    if (empty($_POST['test_type'])) $errors[]='Type of test required';
    if (empty($_POST['project_program'])) $errors[]='Project/Program required';
    if (empty($_POST['test_required_on'])) $errors[]='Test required on date required';

    // DEBUG: Log any validation errors immediately
    if (!empty($errors)) {
        error_log('SUBMIT → VALIDATION BLOCKING: role=' . $user_role . ' | errors=' . json_encode($errors) . ' | user=' . $user->ID);
    }

    if (empty($errors)) {
        // ════════════════════════════════════════════════════════════════════
        // MANAGER ROLE SUBMISSION — SEPARATE PATH (Always INSERT)
        // ════════════════════════════════════════════════════════════════════
        if ($user_role === 'manager') {
            error_log('MANAGER → SUBMIT INITIATED: user=' . $user->ID . ' | name=' . $emp->name);
            
            $qa_required = sanitize_text_field($_POST['qa_exists'] ?? 'no');
            if (!in_array($qa_required, ['yes', 'no'])) {
                $qa_required = 'no';
            }
            $submission_status = ($qa_required === 'yes') ? 'pending_qa' : 'pending';
            
            $manager_submit_data = [
                'test_requisition_no'    => 'PENDING-' . $user->ID . '-' . uniqid(),
                'submission_date'        => date('Y-m-d H:i:s'),
                'satellite_name'         => sanitize_text_field($_POST['satellite_name']),
                'test_type'              => sanitize_text_field($_POST['test_type']),
                'project_program'        => sanitize_text_field($_POST['project_program']),
                'test_required_on'       => sanitize_text_field($_POST['test_required_on']),
                'sub_name'               => sanitize_text_field($_POST['sub_name']),
                'sub_stno'               => sanitize_text_field($_POST['sub_stno']),
                'sub_email'              => sanitize_text_field($_POST['sub_email']),
                'sub_section'            => substr(sanitize_text_field($_POST['sub_section']), 0, 100),
                'sub_division'           => substr(sanitize_text_field($_POST['sub_division'] ?? ''), 0, 100),
                'sub_designation'        => sanitize_text_field($_POST['sub_designation']),
                'sub_phone'              => sanitize_text_field($_POST['sub_phone']),
                'thermal_power'          => intval($_POST['thermal_power'] ?? 0),
                'thermal_thermocouples'  => intval($_POST['thermal_thermocouples'] ?? 0),
                'ground_dc_signal'       => intval($_POST['ground_dc_signal'] ?? 0),
                'ground_signal_power'    => intval($_POST['ground_signal_power'] ?? 0),
                'rf_connector_type'      => sanitize_text_field($_POST['rf_connector_type'] ?? ''),
                'rf_connector_channels'  => intval($_POST['rf_connector_channels'] ?? 0),
                'rf_connector_comments'  => sanitize_text_field($_POST['rf_connector_comments'] ?? ''),
                'thermal_power_comments' => sanitize_text_field($_POST['thermal_power_comments'] ?? ''),
                'thermal_thermocouples_comments' => sanitize_text_field($_POST['thermal_thermocouples_comments'] ?? ''),
                'ground_dc_signal_comments' => sanitize_text_field($_POST['ground_dc_signal_comments'] ?? ''),
                'ground_signal_power_comments' => sanitize_text_field($_POST['ground_signal_power_comments'] ?? ''),
                'special_requirements'   => sanitize_textarea_field($_POST['special_requirements'] ?? ''),
                'qa_exists'              => $qa_required,
                'qa_name'                => sanitize_text_field($_POST['qa_name'] ?? ''),
                'qa_stno'                => sanitize_text_field($_POST['qa_stno'] ?? ''),
                'qa_section'             => sanitize_text_field($_POST['qa_section'] ?? ''),
                'qa_phone'               => sanitize_text_field($_POST['qa_phone'] ?? ''),
                'user_id'                => $user->ID,
                'status'                 => $submission_status,
            ];
            
            // Manager ALWAYS creates NEW record — INSERT only, never UPDATE
            error_log('MANAGER → ATTEMPTING INSERT: tr_no=' . $manager_submit_data['test_requisition_no'] . ' | status=' . $submission_status);
            $insert_result = $wpdb->insert($table, $manager_submit_data);
            
            if ($insert_result !== false) {
                $inserted_id = $wpdb->insert_id;
                error_log('✓ MANAGER SUBMIT → INSERT SUCCESS: id=' . $inserted_id . ' | tr=' . $manager_submit_data['test_requisition_no'] . ' | user=' . $user->ID);
                
                // Send notifications based on status
                if ($submission_status === 'pending_qa') {
                    lssc_notify_qa($wpdb, $manager_submit_data['test_requisition_no'], $emp->name);
                } else {
                    lssc_notify_managers($wpdb, $manager_submit_data['test_requisition_no'], $emp->name);
                }
                
                wp_redirect(add_query_arg('lssc_msg', 'submitted', remove_query_arg(['action','view_id','resume_draft','mgr_action','complete_id','prog_id'], get_permalink())));
                exit;
            } else {
                // Manager INSERT failed — log and show error
                $db_error = $wpdb->last_error;
                error_log('✗ MANAGER SUBMIT → INSERT FAILED: error=' . $db_error . ' | tr=' . $manager_submit_data['test_requisition_no'] . ' | user=' . $user->ID);
                error_log('Table checked: ' . $table . ' | wpdb->last_query: ' . $wpdb->last_query);
                $errors[] = 'Failed to create submission. Database error: ' . $db_error;
            }
        }
        // ════════════════════════════════════════════════════════════════════
        // INDENTER ROLE SUBMISSION — UPDATE or INSERT draft
        // ════════════════════════════════════════════════════════════════════
        else if ($user_role === 'indenter') {
            $draft_id = intval($_POST['draft_id'] ?? 0);
            
            $qa_required = sanitize_text_field($_POST['qa_exists'] ?? 'no');
            if (!in_array($qa_required, ['yes', 'no'])) {
                $qa_required = 'no';
            }
            $submission_status = ($qa_required === 'yes') ? 'pending_qa' : 'pending';
            
            $submit_data = [
                'submission_date'        => date('Y-m-d H:i:s'),
                'satellite_name'         => sanitize_text_field($_POST['satellite_name']),
                'test_type'              => sanitize_text_field($_POST['test_type']),
                'project_program'        => sanitize_text_field($_POST['project_program']),
                'test_required_on'       => sanitize_text_field($_POST['test_required_on']),
                'sub_name'               => sanitize_text_field($_POST['sub_name']),
                'sub_stno'               => sanitize_text_field($_POST['sub_stno']),
                'sub_email'              => sanitize_text_field($_POST['sub_email']),
                'sub_section'            => substr(sanitize_text_field($_POST['sub_section']), 0, 100),
                'sub_division'           => substr(sanitize_text_field($_POST['sub_division'] ?? ''), 0, 100),
                'sub_designation'        => sanitize_text_field($_POST['sub_designation']),
                'sub_phone'              => sanitize_text_field($_POST['sub_phone']),
                'thermal_power'          => intval($_POST['thermal_power'] ?? 0),
                'thermal_thermocouples'  => intval($_POST['thermal_thermocouples'] ?? 0),
                'ground_dc_signal'       => intval($_POST['ground_dc_signal'] ?? 0),
                'ground_signal_power'    => intval($_POST['ground_signal_power'] ?? 0),
                'rf_connector_type'      => sanitize_text_field($_POST['rf_connector_type'] ?? ''),
                'rf_connector_channels'  => intval($_POST['rf_connector_channels'] ?? 0),
                'rf_connector_comments'  => sanitize_text_field($_POST['rf_connector_comments'] ?? ''),
                'thermal_power_comments' => sanitize_text_field($_POST['thermal_power_comments'] ?? ''),
                'thermal_thermocouples_comments' => sanitize_text_field($_POST['thermal_thermocouples_comments'] ?? ''),
                'ground_dc_signal_comments' => sanitize_text_field($_POST['ground_dc_signal_comments'] ?? ''),
                'ground_signal_power_comments' => sanitize_text_field($_POST['ground_signal_power_comments'] ?? ''),
                'special_requirements'   => sanitize_textarea_field($_POST['special_requirements'] ?? ''),
                'qa_exists'              => $qa_required,
                'qa_name'                => sanitize_text_field($_POST['qa_name'] ?? ''),
                'qa_stno'                => sanitize_text_field($_POST['qa_stno'] ?? ''),
                'qa_section'             => sanitize_text_field($_POST['qa_section'] ?? ''),
                'qa_phone'               => sanitize_text_field($_POST['qa_phone'] ?? ''),
                'user_id'                => $user->ID,
                'status'                 => $submission_status,
            ];
            
            $operation_success = false;
            
            // Try to UPDATE existing draft if draft_id provided
            if ($draft_id > 0) {
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, status, user_id FROM {$table} WHERE id=%d AND user_id=%d", 
                    $draft_id, $user->ID
                ));
                
                if ($existing) {
                    if ($existing->status === 'qa_rejected' || $existing->status === 'rejected' || $existing->status === 'recheck_required') {
                        $submit_data['qa_decision']      = '';
                        $submit_data['qa_remarks']       = '';
                        $submit_data['qa_review_date']   = null;
                        $submit_data['qa_reviewer_name'] = '';
                        $submit_data['reviewed_by']      = '';
                        $submit_data['manager_comment']  = '';
                        $submit_data['manager_final_comment'] = '';
                        $submit_data['manager_action']   = 'pending';
                        $submit_data['approval_date']    = null;
                        $submit_data['risk_assessed']    = '';
                        $submit_data['rpn']              = '';
                        $submit_data['risk_record']      = '';
                    }
                    
                    $update_result = $wpdb->update($table, $submit_data, ['id' => $draft_id]);
                    if ($update_result !== false) {
                        $operation_success = true;
                        error_log('INDENTER SUBMIT → UPDATE SUCCESS: draft_id=' . $draft_id . ' user=' . $user->ID);
                    } else {
                        error_log('INDENTER SUBMIT → UPDATE FAILED: ' . $wpdb->last_error);
                    }
                }
            }
            
            // If UPDATE didn't happen or failed, INSERT as new record
            if (!$operation_success) {
                $tr_placeholder = 'PENDING-' . $user->ID . '-' . uniqid();
                $insert_data = array_merge(['test_requisition_no' => $tr_placeholder], $submit_data);
                
                $insert_result = $wpdb->insert($table, $insert_data);
                if ($insert_result !== false) {
                    $operation_success = true;
                    error_log('INDENTER SUBMIT → INSERT SUCCESS: tr=' . $tr_placeholder . ' user=' . $user->ID);
                } else {
                    error_log('INDENTER SUBMIT → INSERT FAILED: ' . $wpdb->last_error);
                }
            }
            
            if ($operation_success) {
                if ($submission_status === 'pending_qa') {
                    lssc_notify_qa($wpdb, '(Pending QA Review)', $emp->name);
                } else {
                    lssc_notify_managers($wpdb, '(Pending Manager Approval)', $emp->name);
                }
                
                wp_redirect(add_query_arg('lssc_msg', 'submitted', remove_query_arg(['action','view_id','resume_draft','mgr_action','complete_id','prog_id'], get_permalink())));
                exit;
            } else {
                $errors[] = 'Failed to save submission. Please check system logs.';
            }
        }
    }
    
    // If we reach here, there were errors
    if (!empty($errors)) {
        set_transient('lssc_errors_'.$user->ID, $errors, 60);
        wp_redirect(add_query_arg('mgr_action', 'create_new', add_query_arg('lssc_msg', 'error', get_permalink())));
        exit;
    }
}

/* ---------- QA ENGINEER REVIEW ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['qa_decision'])) {
    if ($user_role !== 'qa_engineer') wp_die('Unauthorized');
    if (!wp_verify_nonce($_POST['lssc_nonce'], 'lssc_action')) wp_die('Security check failed');
    $form_id    = intval($_POST['form_id']);
    $decision   = ($_POST['qa_decision'] === 'accept') ? 'accept' : 'reject';
    $new_status = ($decision === 'accept') ? 'pending' : 'qa_rejected';
    $wpdb->update($table, [
        'status'           => $new_status,
        'qa_decision'      => $decision,
        'qa_review_date'   => date('Y-m-d H:i:s'),
        'qa_reviewer_name' => $emp->name,
        'qa_remarks'       => sanitize_textarea_field($_POST['qa_remarks'] ?? ''),
    ], ['id' => $form_id]);
    $form = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $form_id));
    if ($decision === 'accept') {
        lssc_notify_managers($wpdb, $form->test_requisition_no, $emp->name);
    } else {
        lssc_notify_indenter($form);
    }
    $msg = ($decision === 'accept') ? 'qa_accepted' : 'qa_rejected';
    wp_redirect(add_query_arg('lssc_msg', $msg, remove_query_arg(['view_id'], get_permalink())));
    exit;
}

/* ---------- MANAGER APPROVE / REJECT / RECHECK ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['manager_action'])) {
    if ($user_role!=='manager') wp_die('Unauthorized');
    if (!wp_verify_nonce($_POST['lssc_nonce'],'lssc_action')) wp_die('Security check failed');
    
    $form_id = intval($_POST['form_id']);
    $action = sanitize_text_field($_POST['manager_action']);
    $manager_final_comment = sanitize_textarea_field($_POST['manager_final_comment'] ?? '');
    
    // Validation: Reject and Recheck require comments
    if (in_array($action, ['reject', 'recheck']) && empty($manager_final_comment)) {
        wp_redirect(add_query_arg('lssc_msg', 'error', add_query_arg('error_msg', 'require_comment_' . $action, add_query_arg('view_id', $form_id, get_permalink()))));
        exit;
    }
    
    // Prepare update data
    $update_data = [
        'manager_id'               => $user->ID,
        'manager_action'           => $action,
        'manager_final_comment'    => $manager_final_comment,
    ];
    
    if ($action === 'approve') {
        // Approve: Update status to 'approved' and store environmental requirements + row comments
        $update_data['status'] = 'approved';
        $update_data['approval_date'] = date('Y-m-d H:i:s');
        $update_data['env_vacuum']               = sanitize_text_field($_POST['env_vacuum'] ?? '');
        $update_data['env_shroud_temp']          = sanitize_text_field($_POST['env_shroud_temp'] ?? '');
        $update_data['env_solar_beam']           = sanitize_text_field($_POST['env_solar_beam'] ?? '');
        $update_data['env_eclipse']              = sanitize_text_field($_POST['env_eclipse'] ?? '');
        $update_data['env_motion_tilt']          = sanitize_text_field($_POST['env_motion_tilt'] ?? '');
        $update_data['env_motion_spin']          = sanitize_text_field($_POST['env_motion_spin'] ?? '');
        $update_data['env_motion_speed']         = sanitize_text_field($_POST['env_motion_speed'] ?? '');
        $update_data['env_mechanical']           = sanitize_text_field($_POST['env_mechanical'] ?? '');
        $update_data['env_special_req']          = sanitize_text_field($_POST['env_special_req'] ?? '');
        $update_data['env_key_char']             = sanitize_text_field($_POST['env_key_char'] ?? '');
        // Store manager comments for each row
        $update_data['env_vacuum_mgr_comment']        = sanitize_textarea_field($_POST['env_vacuum_mgr_comment'] ?? '');
        $update_data['env_shroud_temp_mgr_comment']   = sanitize_textarea_field($_POST['env_shroud_temp_mgr_comment'] ?? '');
        $update_data['env_solar_beam_mgr_comment']    = sanitize_textarea_field($_POST['env_solar_beam_mgr_comment'] ?? '');
        $update_data['env_eclipse_mgr_comment']       = sanitize_textarea_field($_POST['env_eclipse_mgr_comment'] ?? '');
        $update_data['env_motion_tilt_mgr_comment']   = sanitize_textarea_field($_POST['env_motion_tilt_mgr_comment'] ?? '');
        $update_data['env_motion_spin_mgr_comment']   = sanitize_textarea_field($_POST['env_motion_spin_mgr_comment'] ?? '');
        $update_data['env_motion_speed_mgr_comment']  = sanitize_textarea_field($_POST['env_motion_speed_mgr_comment'] ?? '');
        $update_data['env_mechanical_mgr_comment']    = sanitize_textarea_field($_POST['env_mechanical_mgr_comment'] ?? '');
        $update_data['env_special_req_mgr_comment']   = sanitize_textarea_field($_POST['env_special_req_mgr_comment'] ?? '');
        $update_data['env_key_char_mgr_comment']      = sanitize_textarea_field($_POST['env_key_char_mgr_comment'] ?? '');
        
        // Generate TR No on approval
        $year   = date('y');
        $count  = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE test_requisition_no LIKE %s", $year.'LSSC%'));
        $serial = str_pad($count + 1, 4, '0', STR_PAD_LEFT);
        $tr_no  = $year . 'LSSC' . $serial;
        $update_data['test_requisition_no'] = $tr_no;
    } elseif ($action === 'reject') {
        $update_data['status'] = 'rejected';
    } elseif ($action === 'recheck') {
        $update_data['status'] = 'recheck_required';
        $update_data['recheck_sent_to_indenter_date'] = date('Y-m-d H:i:s');
    }
    
    $wpdb->update($table, $update_data, ['id' => $form_id]);
    $form = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $form_id));
    lssc_notify_indenter($form);
    if ($action === 'approve') lssc_notify_lssc($wpdb, $form);
    
    $msg_map = ['approve' => 'approved', 'reject' => 'rejected', 'recheck' => 'recheck_sent'];
    wp_redirect(add_query_arg('lssc_msg', $msg_map[$action], remove_query_arg(['action','view_id','resume_draft','mgr_action','complete_id','prog_id','error_msg'], get_permalink())));
    exit;
}

/* ---------- MANAGER SAVE ENVIRONMENTAL REQUIREMENTS ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_env_requirements'])) {
    if ($user_role!=='manager') wp_die('Unauthorized');
    if (!wp_verify_nonce($_POST['lssc_nonce'],'lssc_action')) wp_die('Security check failed');
    
    $form_id = intval($_POST['form_id']);
    $form = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $form_id));
    
    if (!$form || $form->status !== 'approved') {
        wp_die('Invalid request or form not in approved status');
    }
    
    // Save all environmental requirements and manager comments
    $update_data = [
        'status'                        => 'in_testing',  // Change status to prevent further edits
        'env_vacuum'                    => sanitize_text_field($_POST['env_vacuum'] ?? ''),
        'env_shroud_temp'               => sanitize_text_field($_POST['env_shroud_temp'] ?? ''),
        'env_solar_beam'                => sanitize_text_field($_POST['env_solar_beam'] ?? ''),
        'env_eclipse'                   => sanitize_text_field($_POST['env_eclipse'] ?? ''),
        'env_motion_tilt'               => sanitize_text_field($_POST['env_motion_tilt'] ?? ''),
        'env_motion_spin'               => sanitize_text_field($_POST['env_motion_spin'] ?? ''),
        'env_motion_speed'              => sanitize_text_field($_POST['env_motion_speed'] ?? ''),
        'env_mechanical'                => sanitize_text_field($_POST['env_mechanical'] ?? ''),
        'env_special_req'               => sanitize_text_field($_POST['env_special_req'] ?? ''),
        'env_key_char'                  => sanitize_text_field($_POST['env_key_char'] ?? ''),
        'env_vacuum_mgr_comment'        => sanitize_textarea_field($_POST['env_vacuum_mgr_comment'] ?? ''),
        'env_shroud_temp_mgr_comment'   => sanitize_textarea_field($_POST['env_shroud_temp_mgr_comment'] ?? ''),
        'env_solar_beam_mgr_comment'    => sanitize_textarea_field($_POST['env_solar_beam_mgr_comment'] ?? ''),
        'env_eclipse_mgr_comment'       => sanitize_textarea_field($_POST['env_eclipse_mgr_comment'] ?? ''),
        'env_motion_tilt_mgr_comment'   => sanitize_textarea_field($_POST['env_motion_tilt_mgr_comment'] ?? ''),
        'env_motion_spin_mgr_comment'   => sanitize_textarea_field($_POST['env_motion_spin_mgr_comment'] ?? ''),
        'env_motion_speed_mgr_comment'  => sanitize_textarea_field($_POST['env_motion_speed_mgr_comment'] ?? ''),
        'env_mechanical_mgr_comment'    => sanitize_textarea_field($_POST['env_mechanical_mgr_comment'] ?? ''),
        'env_special_req_mgr_comment'   => sanitize_textarea_field($_POST['env_special_req_mgr_comment'] ?? ''),
        'env_key_char_mgr_comment'      => sanitize_textarea_field($_POST['env_key_char_mgr_comment'] ?? ''),
    ];
    
    $wpdb->update($table, $update_data, ['id' => $form_id]);
    
    if ($wpdb->last_error) {
        error_log('Error saving environmental requirements: ' . $wpdb->last_error);
        wp_redirect(add_query_arg('lssc_msg', 'error', remove_query_arg(['view_id', 'action', 'mgr_action', 'complete_id', 'prog_id'], get_permalink())));
    } else {
        wp_redirect(add_query_arg('lssc_msg', 'env_req_saved', remove_query_arg(['view_id', 'action', 'mgr_action', 'complete_id', 'prog_id'], get_permalink())));
    }
    exit;
}

/* ---------- LSSC SAVE DRAFT ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_draft'])) {
    if ($user_role!=='lssc') wp_die('Unauthorized');
    if (!wp_verify_nonce($_POST['lssc_nonce'],'lssc_action')) wp_die('Security check failed');
    
    $form_id = intval($_POST['form_id']);
    $errors = [];
    
    // Validate test dates
    $test_started = sanitize_text_field($_POST['test_started_datetime'] ?? '');
    $test_completed = sanitize_text_field($_POST['test_completed_datetime'] ?? '');
    
    if ($test_started && $test_completed) {
        $start_ts = strtotime(str_replace('T', ' ', $test_started));
        $end_ts = strtotime(str_replace('T', ' ', $test_completed));
        
        if ($end_ts && $start_ts && $end_ts < $start_ts) {
            $errors[] = 'Test Completed date cannot be before Test Started date';
        }
    }
    
    // Validate Yes/No fields format
    $yes_no_fields = ['risk_assessed_lssc', 'risk_form_filled', 'special_processes', 
                      'test_received_reviewed', 'test_object_accepted', 'test_on_time'];
    
    foreach ($yes_no_fields as $field) {
        $value = strtolower(trim($_POST[$field] ?? ''));
        if ($value && $value !== 'yes' && $value !== 'no') {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' must be "Yes" or "No"';
        }
    }
    
    // If errors exist, show them
    if (!empty($errors)) {
        set_transient('lssc_errors_'.$user->ID, $errors, 60);
        wp_redirect(add_query_arg('lssc_msg', 'validation_error', add_query_arg('complete_id', $form_id, get_permalink())));
        exit;
    }
    
    // Calculate test duration if both dates present
    $test_duration = '';
    if ($test_started && $test_completed) {
        $start_ts = strtotime(str_replace('T', ' ', $test_started));
        $end_ts = strtotime(str_replace('T', ' ', $test_completed));
        if ($end_ts && $start_ts) {
            $diff_secs = $end_ts - $start_ts;
            $hours = intval($diff_secs / 3600);
            $mins = intval(($diff_secs % 3600) / 60);
            $secs = intval($diff_secs % 60);
            $test_duration = sprintf('%02d:%02d:%02d', $hours, $mins, $secs);
        }
    }
    
    // Auto-fill signatures if empty
    $specimen_name = sanitize_text_field($_POST['specimen_collected_by_name'] ?? '');
    $specimen_sig = sanitize_text_field($_POST['specimen_collected_by_sig'] ?? '');
    if (empty($specimen_name)) {
        $specimen_name = $emp->name;
    }
    if (empty($specimen_sig)) {
        $specimen_sig = $emp->name . ' - ' . date('d/m/Y H:i');
    }
    
    $verify_name = sanitize_text_field($_POST['verification_closed_by_name'] ?? '');
    $verify_sig = sanitize_text_field($_POST['verification_closed_by_sig'] ?? '');
    if (empty($verify_name)) {
        $verify_name = $emp->name;
    }
    if (empty($verify_sig)) {
        $verify_sig = $emp->name . ' - ' . date('d/m/Y H:i');
    }
    
    // Normalize Yes/No fields
    $update_data = [
        'requisition_received_date'     => sanitize_text_field($_POST['requisition_received_date'] ?? ''),
        'risk_assessed_lssc'            => strtolower(trim($_POST['risk_assessed_lssc'] ?? '')) === 'yes' ? 'Yes' : (trim($_POST['risk_assessed_lssc'] ?? '') === '' ? '' : 'No'),
        'rpn_lssc'                      => sanitize_text_field($_POST['rpn_lssc'] ?? ''),
        'risk_form_filled'              => strtolower(trim($_POST['risk_form_filled'] ?? '')) === 'yes' ? 'Yes' : (trim($_POST['risk_form_filled'] ?? '') === '' ? '' : 'No'),
        'special_processes'             => strtolower(trim($_POST['special_processes'] ?? '')) === 'yes' ? 'Yes' : (trim($_POST['special_processes'] ?? '') === '' ? '' : 'No'),
        'test_received_reviewed'        => strtolower(trim($_POST['test_received_reviewed'] ?? '')) === 'yes' ? 'Yes' : (trim($_POST['test_received_reviewed'] ?? '') === '' ? '' : 'No'),
        'test_object_accepted'          => strtolower(trim($_POST['test_object_accepted'] ?? '')) === 'yes' ? 'Yes' : (trim($_POST['test_object_accepted'] ?? '') === '' ? '' : 'No'),
        'test_accepted_by'              => sanitize_text_field($_POST['test_accepted_by'] ?? ''),
        'test_started_datetime'         => str_replace('T', ' ', sanitize_text_field($_POST['test_started_datetime'] ?? '')),
        'test_completed_datetime'       => str_replace('T', ' ', sanitize_text_field($_POST['test_completed_datetime'] ?? '')),
        'test_duration'                 => $test_duration,
        'test_on_time'                  => strtolower(trim($_POST['test_on_time'] ?? '')) === 'yes' ? 'Yes' : (trim($_POST['test_on_time'] ?? '') === '' ? '' : 'No'),
        'specimen_collected_by_name'    => $specimen_name,
        'specimen_collected_by_sig'     => $specimen_sig,
        'verification_closed_by_name'   => $verify_name,
        'verification_closed_by_sig'    => $verify_sig,
        'status'                        => 'Draft',
        'draft_saved_at'                => date('Y-m-d H:i:s'),
        'draft_saved_by'                => $emp->name,
    ];
    
    $wpdb->update($table, $update_data, ['id'=>$form_id]);
    
    wp_redirect(add_query_arg('lssc_msg', 'lssc_draft_saved', remove_query_arg(['action','complete_id','view_id'], get_permalink())));
    exit;
}

/* ---------- LSSC COMPLETE ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['complete_lssc'])) {
    if ($user_role!=='lssc') wp_die('Unauthorized');
    if (!wp_verify_nonce($_POST['lssc_nonce'],'lssc_action')) wp_die('Security check failed');
    
    $form_id = intval($_POST['form_id']);
    $errors = [];
    
    // ===== MANDATORY FIELD VALIDATION =====
    $mandatory_fields = [
        'requisition_received_date' => 'Requisition Received Date',
        'risk_assessed_lssc' => 'Risk Assessed (Yes/No)',
        'test_started_datetime' => 'Test Started Date/Time',
        'test_completed_datetime' => 'Test Completed Date/Time',
        'specimen_collected_by_name' => 'Specimen Collected by Name',
        'verification_closed_by_name' => 'Verification Closed by Name',
    ];
    
    foreach ($mandatory_fields as $field_name => $field_label) {
        $value = trim($_POST[$field_name] ?? '');
        if (empty($value)) {
            $errors[] = $field_label . ' is required';
        }
    }
    
    // ===== DATE VALIDATION =====
    $test_started = sanitize_text_field($_POST['test_started_datetime'] ?? '');
    $test_completed = sanitize_text_field($_POST['test_completed_datetime'] ?? '');
    
    if ($test_started && $test_completed) {
        $start_ts = strtotime(str_replace('T', ' ', $test_started));
        $end_ts = strtotime(str_replace('T', ' ', $test_completed));
        
        if ($end_ts && $start_ts && $end_ts < $start_ts) {
            $errors[] = 'Test Completed date/time must be after Test Started date/time';
        }
    }
    
    // ===== YES/NO FIELD VALIDATION =====
    $yes_no_fields = ['risk_assessed_lssc', 'risk_form_filled', 'special_processes', 
                      'test_received_reviewed', 'test_object_accepted', 'test_on_time'];
    
    foreach ($yes_no_fields as $field) {
        $value = strtolower(trim($_POST[$field] ?? ''));
        if ($value && $value !== 'yes' && $value !== 'no') {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ': Please enter "Yes" or "No"';
        }
    }
    
    // ===== RPN >= 5 REQUIRES RISK ASSESSMENT FORM = "Yes" =====
    $rpn_val = intval($_POST['rpn_lssc'] ?? 0);
    $risk_form_filled = strtolower(trim($_POST['risk_form_filled'] ?? ''));
    
    if ($rpn_val >= 5 && $risk_form_filled !== 'yes') {
        $errors[] = 'IMPORTANT: If RPN ≥ 5, Risk Assessment Form MUST be marked "Yes"';
    }
    
    // Show validation errors if any
    if (!empty($errors)) {
        set_transient('lssc_errors_'.$user->ID, $errors, 60);
        wp_redirect(add_query_arg('lssc_msg', 'validation_error', add_query_arg('complete_id', $form_id, get_permalink())));
        exit;
    }
    
    // ===== CALCULATE TEST DURATION =====
    $test_duration = '';
    if ($test_started && $test_completed) {
        $start_ts = strtotime(str_replace('T', ' ', $test_started));
        $end_ts = strtotime(str_replace('T', ' ', $test_completed));
        if ($end_ts && $start_ts) {
            $diff_secs = $end_ts - $start_ts;
            $hours = intval($diff_secs / 3600);
            $mins = intval(($diff_secs % 3600) / 60);
            $secs = intval($diff_secs % 60);
            $test_duration = sprintf('%02d:%02d:%02d', $hours, $mins, $secs);
        }
    }
    
    // ===== AUTO-FILL SIGNATURES WITH TIMESTAMP =====
    $specimen_name = sanitize_text_field($_POST['specimen_collected_by_name'] ?? '');
    $specimen_sig = sanitize_text_field($_POST['specimen_collected_by_sig'] ?? '');
    
    if (empty($specimen_name)) {
        $specimen_name = $emp->name;
    }
    if (empty($specimen_sig)) {
        $specimen_sig = $emp->name . ' - ' . date('d/m/Y H:i');
    }
    
    $verify_name = sanitize_text_field($_POST['verification_closed_by_name'] ?? '');
    $verify_sig = sanitize_text_field($_POST['verification_closed_by_sig'] ?? '');
    
    if (empty($verify_name)) {
        $verify_name = $emp->name;
    }
    if (empty($verify_sig)) {
        $verify_sig = $emp->name . ' - ' . date('d/m/Y H:i');
    }
    
    // ===== NORMALIZE YES/NO FIELDS =====
    $update_data = [
        'requisition_received_date'     => sanitize_text_field($_POST['requisition_received_date'] ?? ''),
        'risk_assessed_lssc'            => strtolower(trim($_POST['risk_assessed_lssc'] ?? '')) === 'yes' ? 'Yes' : 'No',
        'rpn_lssc'                      => sanitize_text_field($_POST['rpn_lssc'] ?? ''),
        'risk_form_filled'              => strtolower(trim($_POST['risk_form_filled'] ?? '')) === 'yes' ? 'Yes' : 'No',
        'special_processes'             => strtolower(trim($_POST['special_processes'] ?? '')) === 'yes' ? 'Yes' : 'No',
        'test_received_reviewed'        => strtolower(trim($_POST['test_received_reviewed'] ?? '')) === 'yes' ? 'Yes' : 'No',
        'test_object_accepted'          => strtolower(trim($_POST['test_object_accepted'] ?? '')) === 'yes' ? 'Yes' : 'No',
        'test_accepted_by'              => sanitize_text_field($_POST['test_accepted_by'] ?? ''),
        'test_started_datetime'         => str_replace('T', ' ', sanitize_text_field($_POST['test_started_datetime'] ?? '')),
        'test_completed_datetime'       => str_replace('T', ' ', sanitize_text_field($_POST['test_completed_datetime'] ?? '')),
        'test_duration'                 => $test_duration,
        'test_on_time'                  => strtolower(trim($_POST['test_on_time'] ?? '')) === 'yes' ? 'Yes' : 'No',
        'specimen_collected_by_name'    => $specimen_name,
        'specimen_collected_by_sig'     => $specimen_sig,
        'verification_closed_by_name'   => $verify_name,
        'verification_closed_by_sig'    => $verify_sig,
        'status'                        => 'completed',
        'completion_date'               => date('Y-m-d H:i:s'),
    ];
    
    $wpdb->update($table, $update_data, ['id'=>$form_id]);
    
    wp_redirect(add_query_arg('lssc_msg', 'lssc_completed', remove_query_arg(['action','complete_id','view_id'], get_permalink())));
    exit;
}

// ========== ALL POST HANDLERS DONE — NOW SAFE TO SEND HEADERS ==========

// Route `action=staff_dashboard&request_id=ID` → internal `complete_id=ID`
// This validates the request exists and is approved, then redirects to the
// existing LSSC staff handler (which shows the staff form when `complete_id` is present).
if (isset($_GET['action']) && $_GET['action'] === 'staff_dashboard') {
  $req_id = intval($_GET['request_id'] ?? 0);
  if ($req_id) {
    $found = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE id=%d AND status=%s", $req_id, 'approved'));
    if ($found) {
      wp_redirect(add_query_arg('complete_id', $found, get_permalink()));
      exit;
    }
  }
  // Fallback: go to dashboard
  wp_redirect(get_permalink());
  exit;
}

get_header();

?>

<style>
*{box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;font-size:15px;line-height:1.6}
.container{max-width:1600px;margin:30px auto;padding:40px;background:#fff}
.form-container{max-width:1400px;margin:auto;background:#fff;padding:40px}
table{width:100%;border-collapse:collapse;font-size:14px;margin-bottom:20px}
th,td{border:1px solid #000;padding:12px 15px;vertical-align:middle}
th{background:#f5f5f5;font-weight:600;font-size:14px}
.label{background:#f5f5f5;font-weight:600}
.block{width:100%;height:38px;border:1px solid #000;padding:8px 12px;font-size:14px}
textarea{width:100%;min-height:80px;border:1px solid #000;resize:vertical;padding:12px;font-size:14px;font-family:inherit;line-height:1.5}
.request-card{border:1px solid #ddd;margin:20px 0;padding:30px;background:#fff}
.request-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:25px;padding-bottom:20px;border-bottom:2px solid #000}
.badge{padding:8px 18px;font-size:13px;font-weight:600;letter-spacing:.8px;border-radius:4px;display:inline-block;}
.badge-pending{background:#ffc107;color:#000}
.badge-approved{background:#28a745;color:#fff}
.badge-rejected{background:#dc3545;color:#fff}
.badge-completed{background:#000;color:#fff}
.badge-recheck-required{background:#0d6efd;color:#fff}
.badge-pending-qa{background:#6f42c1;color:#fff}
.badge-qa-rejected{background:#fd7e14;color:#fff}
.manager-fields{margin-top:25px;padding:25px;background:#fff;border:2px solid #000}
.manager-fields h4{margin:0 0 20px 0;font-size:16px;font-weight:600;text-transform:uppercase;letter-spacing:.8px}
.btn{padding:12px 28px;border:none;cursor:pointer;font-weight:600;font-size:13px;text-transform:uppercase;letter-spacing:.8px;transition:all .2s;text-decoration:none;display:inline-block;border-radius:4px;}
.btn:hover{opacity:.85}
.btn-primary{background:#000;color:#fff}
.btn-success{background:#28a745;color:#fff}
.btn-approve{background:#28a745;color:#fff}
.btn-reject{background:#dc3545;color:#fff}
.btn-draft{background:#17a2b8;color:#fff}
.btn-info{background:#17a2b8;color:#fff}
.btn-view{background:#000;color:#fff;padding:10px 20px;font-size:13px}
.btn-submit{background:#000;color:#fff;padding:14px 35px;border:none;cursor:pointer;font-weight:600;font-size:14px;text-transform:uppercase;border-radius:4px;}
.btn-complete-submit{background:#28a745;color:#fff;padding:14px 30px;border:none;cursor:pointer;font-weight:600;font-size:14px;text-transform:uppercase;border-radius:4px;}
.btn-draft-final{background:#17a2b8;color:#fff;padding:12px 28px;border:none;cursor:pointer;font-weight:600;font-size:13px;text-transform:uppercase;letter-spacing:.8px;border-radius:4px;transition:all .2s;}
.btn-draft-final:hover:not(:disabled){background:#138496;}
.btn-draft-final:disabled{opacity:0.5;cursor:not-allowed;}
.btn-complete-final{background:#28a745;color:#fff;padding:12px 28px;border:none;cursor:pointer;font-weight:600;font-size:13px;text-transform:uppercase;letter-spacing:.8px;border-radius:4px;transition:all .2s;}
.btn-complete-final:hover:not(:disabled){background:#218838;}
.btn-complete-final:disabled{opacity:0.5;cursor:not-allowed;}
.btn-test-details{background:#007bff;color:#fff;padding:10px 20px;font-size:13px;margin-left:10px;}
.view-only{background:#f5f5f5;color:#000}
.role-indicator{padding:16px 30px;margin-bottom:30px;font-weight:600;text-align:center;background:#000;color:#fff;font-size:14px;text-transform:uppercase;letter-spacing:1.5px}
.list-table{width:100%;border-collapse:collapse;margin-top:25px;border:1px solid #000}
.list-table th{background:#000;color:#fff;padding:15px;text-align:left;font-weight:600;font-size:13px;text-transform:uppercase;letter-spacing:.8px}
.list-table td{padding:15px;border-bottom:1px solid #ddd;font-size:14px}
.list-table tbody tr{background:#fff}
.list-table tbody tr:hover{background:#f8f9fa}
.draft-notice{background:#fff3cd;border:2px solid #ffc107;padding:15px 20px;margin:20px 0;border-radius:4px}
.status-badge-draft{display:inline-block;background:#6c757d;color:#fff;padding:12px 24px;border-radius:6px;font-weight:600;font-size:14px;text-transform:uppercase;letter-spacing:1px}
.status-badge-completed{display:inline-block;background:#28a745;color:#fff;padding:12px 24px;border-radius:6px;font-weight:600;font-size:14px;text-transform:uppercase;letter-spacing:1px}
.qa_field{display:none;}
.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin-bottom:35px}
.stat-card{padding:28px 24px;border:2px solid;text-align:center;border-radius:8px;}
.stat-card .stat-num{font-size:52px;font-weight:700;line-height:1;margin-bottom:8px}
.stat-card .stat-lbl{font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:1px}
.sc-pending{border-color:#ffc107;background:#fffdf0;color:#856404}
.sc-approved{border-color:#28a745;background:#f0fff4;color:#155724}
.mgr-tabs{display:flex;gap:0;margin-bottom:30px;border-bottom:2px solid #000;flex-wrap:wrap;}
.mgr-tab{padding:14px 26px;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.8px;cursor:pointer;border:2px solid transparent;border-bottom:none;margin-bottom:-2px;background:#fff;color:#666;text-decoration:none;display:inline-block;white-space:nowrap}
.mgr-tab.active{background:#000;color:#fff;border-color:#000}
.mgr-tab:hover:not(.active){background:#f5f5f5;color:#000}
.mgr-tab-new{background:#28a745!important;color:#fff!important;margin-left:auto;border-color:#28a745!important}
.radio-row{display:flex;align-items:center;gap:15px;margin:10px 0;flex-wrap:wrap;}
.radio-item{display:flex;align-items:center;gap:8px;flex:1;min-width:180px;}
h1{font-size:24px;font-weight:600;text-transform:uppercase;letter-spacing:1px;margin-bottom:25px}
h2{font-size:22px;font-weight:600}
h3{font-size:18px;font-weight:600;margin:25px 0 15px 0;text-transform:uppercase;letter-spacing:.8px}
p{font-size:15px;line-height:1.6}
.btn-view{background:#000;color:#fff;padding:6px 14px;border-radius:4px;text-decoration:none;font-size:13px;display:inline-block;border:none;cursor:pointer;font-weight:600;text-transform:uppercase;letter-spacing:.8px}
.btn-view:hover{background:#333}
</style>

<?php

// =====================================================================
//  SHARED: REQUEST FORM HTML
// =====================================================================
function lssc_request_form($emp, $draft=null, $ajax_url='') { 
    $d = $draft;
    // resubmit_mode: form opened from qa_rejected, rejected, or manager recheck — editable fields based on mode
    $resubmit = (!empty($d->status) && in_array($d->status, ['qa_rejected','rejected','recheck_required']));
    $ro       = $resubmit ? 'readonly' : '';          // readonly attr for locked fields
    $ro_bg    = $resubmit ? 'background:#f5f5f5;' : ''; // grey bg for locked fields
?>
<form method="post" enctype="multipart/form-data">
<?php wp_nonce_field('lssc_action','lssc_nonce'); ?>
<?php if (!empty($d->id) && in_array($d->status, ['draft_indenter', 'qa_rejected', 'rejected', 'recheck_required'])): ?>
<input type="hidden" name="draft_id" value="<?php echo intval($d->id); ?>">
<?php endif; ?>

<?php if ($resubmit): ?>
<?php if ($d->status === 'rejected'): ?>
<div style="background:#f8d7da;border:2px solid #dc3545;padding:16px 22px;margin-bottom:24px;border-radius:6px;">
  <strong style="color:#721c24;font-size:15px;">&#10060; Rejected by Manager</strong><br>
  <span style="font-size:14px;color:#555;display:block;margin-top:6px;">
    <strong>Manager Comments:</strong> <em><?php echo esc_html($d->manager_comment ?: '—'); ?></em>
  </span>
  <span style="font-size:13px;color:#721c24;display:block;margin-top:10px;">
    &#128274; All fields are locked. Only <strong>Chamber Interface Requirements</strong> can be edited before resubmitting.
  </span>
</div>
<?php elseif ($d->status === 'recheck_required'): ?>
<div style="background:#cfe2ff;border:2px solid #0d6efd;padding:16px 22px;margin-bottom:24px;border-radius:6px;">
  <strong style="color:#084298;font-size:15px;">⟲ Manager Requested Changes</strong><br>
  <span style="font-size:14px;color:#555;display:block;margin-top:6px;">
    <strong>Required Changes:</strong> <em><?php echo esc_html($d->manager_final_comment ?: '—'); ?></em>
  </span>
  <span style="font-size:13px;color:#084298;display:block;margin-top:10px;">
    &#128274; All fields are locked. Only <strong>Chamber Interface Requirements</strong> can be edited before resubmitting for review.
  </span>
</div>
<?php else: ?>
<div style="background:#fff3cd;border:2px solid #fd7e14;padding:16px 22px;margin-bottom:24px;border-radius:6px;">
  <strong style="color:#856404;font-size:15px;">&#9888; Returned by QA Engineer</strong><br>
  <span style="font-size:14px;color:#555;display:block;margin-top:6px;">
    <strong>Remarks:</strong> <em><?php echo esc_html($d->qa_remarks ?: '—'); ?></em>
  </span>
  <span style="font-size:13px;color:#856404;display:block;margin-top:10px;">
    &#128274; All fields are locked. Only <strong>Chamber Interface Requirements</strong> can be edited before resubmitting.
  </span>
</div>
<?php endif; ?>
<?php elseif (!empty($d->indenter_draft_saved_at)): ?>
<div class="draft-notice"><strong>&#128203; Draft Saved:</strong> Last saved by <strong><?php echo esc_html($d->indenter_draft_saved_by ?? 'Unknown'); ?></strong> on <strong><?php echo date('d M Y, h:i A', strtotime($d->indenter_draft_saved_at)); ?></strong></div>
<?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:30px;font-size:14px;">
  <div><strong style="font-size:15px;">LSSC LAB</strong><br>ENVIRONMENTAL TEST FACILITY<br><strong>ISITE, U. R. Rao Satellite Centre</strong></div>
  <div style="text-align:right">
    <strong style="font-size:15px;">TEST REQUEST FORM</strong><br>Large Space Simulation Chamber<br><br>
    <span style="font-size:13px;color:#856404;background:#fff3cd;border:1px solid #ffc107;padding:6px 14px;display:inline-block;border-radius:4px;">
      &#9432; Test Requisition No. will be assigned upon manager approval
    </span>
  </div>
</div>

<?php
$qa_exists_val     = $d->qa_exists  ?? 'no';
$qa_name_val       = $d->qa_name    ?? '';
$qa_stno_val       = $d->qa_stno    ?? '';
$qa_section_val    = $d->qa_section ?? '';
$qa_phone_val      = $d->qa_phone   ?? '';
$qa_display        = ($qa_exists_val === 'yes') ? 'table-cell' : 'none';
$qa_search_display = ($qa_exists_val === 'yes') ? 'block' : 'none';
?>

<!-- ── ENGINEER DETAILS (fully locked in resubmit mode) ── -->
<table>
<tr>
  <th style="width:20%;background:#000;color:#fff;text-align:left;"></th>
  <th style="background:#000;color:#fff;text-align:left;">SUBSYSTEM ENGINEER <small style="font-weight:400;">(Auto-filled)</small></th>
  <th style="background:#000;color:#fff;text-align:left;">QA / T&amp;E ENGINEER</th>
</tr>
<tr>
  <td class="label"></td>
  <td style="background:#f5f5f5;font-size:13px;color:#555;">Details auto-filled from your profile</td>
  <td>
    <?php if ($resubmit): ?>
      <!-- Locked: preserve existing qa_exists value as hidden input -->
      <input type="hidden" name="qa_exists" value="<?php echo esc_attr($qa_exists_val); ?>">
      <span style="font-size:14px;color:#555;font-style:italic;">
        <?php echo $qa_exists_val === 'yes' ? 'QA Engineer assigned' : 'No QA Engineer'; ?>
      </span>
    <?php else: ?>
      <label style="margin-right:15px;font-weight:normal;">
        <input type="radio" name="qa_exists" value="yes" onchange="toggleQA()" <?php echo ($qa_exists_val==='yes')?'checked':''; ?>> Yes
      </label>
      <label style="font-weight:normal;">
        <input type="radio" name="qa_exists" value="no"  onchange="toggleQA()" <?php echo ($qa_exists_val!=='yes')?'checked':''; ?>> No
      </label>
      <div id="qa_search" style="display:<?php echo $qa_search_display;?>;margin-top:10px;">
        <input type="text" id="qa_stno_search" placeholder="Enter Staff No." style="width:160px;padding:8px;border:1px solid #000;font-size:14px;">
        <button type="button" onclick="fetchQAData()" style="padding:8px 16px;background:#000;color:#fff;border:none;cursor:pointer;font-size:13px;margin-left:6px;">Search</button>
      </div>
    <?php endif; ?>
  </td>
</tr>
<tr>
  <td class="label">Name</td>
  <td><input class="block" name="sub_name" value="<?php echo esc_attr($emp->name ?? ''); ?>" readonly style="background:#f5f5f5;"></td>
  <td class="qa_field" style="display:<?php echo $qa_display;?>"><input class="block" id="qa_name" name="qa_name" value="<?php echo esc_attr($qa_name_val);?>" readonly style="background:#f5f5f5;"></td>
</tr>
<tr>
  <td class="label">Staff No.</td>
  <td><input class="block" name="sub_stno" value="<?php echo esc_attr($emp->stno ?? ''); ?>" readonly style="background:#f5f5f5;"></td>
  <td class="qa_field" style="display:<?php echo $qa_display;?>"><input class="block" id="qa_stno" name="qa_stno" value="<?php echo esc_attr($qa_stno_val);?>" readonly style="background:#f5f5f5;"></td>
</tr>
<tr>
  <td class="label">Email</td>
  <td><input class="block" name="sub_email" value="<?php echo esc_attr($emp->email ?? ''); ?>" readonly style="background:#f5f5f5;"></td>
  <td class="qa_field" style="display:<?php echo $qa_display;?>"><input class="block" id="qa_email" name="qa_email" readonly style="background:#f5f5f5;"></td>
</tr>
<tr>
  <td class="label">Section / Division</td>
  <td>
    <input class="block" name="sub_section" value="<?php echo esc_attr(($emp->sectionfullname ?? '') . ' / ' . ($emp->divisionfullname ?? '')); ?>" readonly style="background:#f5f5f5;">
    <input type="hidden" name="sub_division" value="<?php echo esc_attr($emp->divisionfullname ?? ''); ?>">
  </td>
  <td class="qa_field" style="display:<?php echo $qa_display;?>"><input class="block" id="qa_section" name="qa_section" value="<?php echo esc_attr($qa_section_val);?>" readonly style="background:#f5f5f5;"></td>
</tr>
<tr>
  <td class="label">Designation</td>
  <td><input class="block" name="sub_designation" value="<?php echo esc_attr($emp->desgn ?? ''); ?>" readonly style="background:#f5f5f5;"></td>
  <td class="qa_field" style="display:<?php echo $qa_display;?>"></td>
</tr>
<tr>
  <td class="label">Telephone No.</td>
  <td><input class="block" name="sub_phone" value="<?php echo esc_attr($emp->telephoneno ?? ''); ?>" readonly style="background:#f5f5f5;"></td>
  <td class="qa_field" style="display:<?php echo $qa_display;?>"><input class="block" id="qa_phone" name="qa_phone" value="<?php echo esc_attr($qa_phone_val);?>" readonly style="background:#f5f5f5;"></td>
</tr>
</table>

<!-- ── TEST OBJECT DETAILS (locked in resubmit mode) ── -->
<table>
<tr>
  <th colspan="4" style="text-align:left;background:#000;color:#fff;">Test Object Details</th>
</tr>
<tr>
  <th style="width:25%">Name of the Satellite/Test Object</th>
  <td colspan="3"><input class="block" name="satellite_name" value="<?php echo esc_attr($d->satellite_name ?? ''); ?>" <?php echo $ro; ?> style="<?php echo $ro_bg; ?>" required></td>
</tr>
<tr>
  <th>Type of Test (TVP/TBT/Any other)</th>
  <td><input class="block" name="test_type" value="<?php echo esc_attr($d->test_type ?? ''); ?>" <?php echo $ro; ?> style="<?php echo $ro_bg; ?>" required></td>
  <th style="width:20%">Project/Program</th>
  <td><input class="block" name="project_program" value="<?php echo esc_attr($d->project_program ?? ''); ?>" <?php echo $ro; ?> style="<?php echo $ro_bg; ?>" required></td>
</tr>
<tr>
  <th>Test Required on</th>
  <td colspan="3"><input type="date" class="block" name="test_required_on" value="<?php echo esc_attr($d->test_required_on ?? ''); ?>" <?php echo $ro; ?> style="<?php echo $ro_bg; ?>max-width:220px;" required></td>
</tr>
</table>

<!-- ── CHAMBER INTERFACE REQUIREMENTS (EDITABLE even in resubmit mode) ── -->
<table>
<tr>
  <th colspan="4" style="text-align:left;background:#000;color:#fff;">
    Chamber Interface Requirements
    <?php if ($resubmit): ?>
      <span style="font-weight:400;font-size:12px;background:#28a745;color:#fff;padding:3px 10px;border-radius:4px;margin-left:12px;">&#9998; EDITABLE</span>
    <?php endif; ?>
  </th>
</tr>
<tr>
  <th style="width:8%">Sl. No.</th>
  <th style="width:42%">Description</th>
  <th style="width:18%">No. of Channels</th>
  <th style="width:32%">Comments</th>
</tr>
<tr>
  <td>1</td>
  <td>Thermal Power channels</td>
  <td style="text-align:center;">
    <input type="number" name="thermal_power" min="0"
           value="<?php echo isset($d->thermal_power) ? intval($d->thermal_power) : ''; ?>"
           style="width:90px;height:38px;padding:8px 12px;border:1px solid #000;text-align:center;">
  </td>
  <td>
    <input class="block"
           name="thermal_power_comments"
           value="<?php echo esc_attr($d->thermal_power_comments ?? ''); ?>">
  </td>
</tr>

<tr>
  <td>2</td>
  <td>Thermal Thermocouples</td>
  <td style="text-align:center;">
    <input type="number" name="thermal_thermocouples" min="0"
           value="<?php echo isset($d->thermal_thermocouples) ? intval($d->thermal_thermocouples) : ''; ?>"
           style="width:90px;height:38px;padding:8px 12px;border:1px solid #000;text-align:center;">
  </td>
  <td>
    <input class="block"
           name="thermal_thermocouples_comments"
           value="<?php echo esc_attr($d->thermal_thermocouples_comments ?? ''); ?>">
  </td>
</tr>

<tr>
  <td>3</td>
  <td>Ground Checkout DC Signal Lines</td>
  <td style="text-align:center;">
    <input type="number" name="ground_dc_signal" min="0"
           value="<?php echo isset($d->ground_dc_signal) ? intval($d->ground_dc_signal) : ''; ?>"
           style="width:90px;height:38px;padding:8px 12px;border:1px solid #000;text-align:center;">
  </td>
  <td>
    <input class="block"
           name="ground_dc_signal_comments"
           value="<?php echo esc_attr($d->ground_dc_signal_comments ?? ''); ?>">
  </td>
</tr>

<tr>
  <td>4</td>
  <td>Ground Signal Power Lines</td>
  <td style="text-align:center;">
    <input type="number" name="ground_signal_power" min="0"
           value="<?php echo isset($d->ground_signal_power) ? intval($d->ground_signal_power) : ''; ?>"
           style="width:90px;height:38px;padding:8px 12px;border:1px solid #000;text-align:center;">
  </td>
  <td>
    <input class="block"
           name="ground_signal_power_comments"
           value="<?php echo esc_attr($d->ground_signal_power_comments ?? ''); ?>">
  </td>
</tr>

<?php
$selected_rf       = $d->rf_connector_type ?? '';
$rf_saved_channels = isset($d->rf_connector_channels) ? intval($d->rf_connector_channels) : '';
$rf_saved_comments = $d->rf_connector_comments ?? '';
?>

<tr>
  <td>5</td>
  <td>
    <strong style="display:block;margin-bottom:8px;">
      Ground Checkout R F Connectors
    </strong>

    <?php 
    $rf_options = [
        'N-type Connector',
        'SMA Connector',
        '2.92 mm Connectors',
        '1553 Connector',
        'Others (if any)'
    ];
    foreach ($rf_options as $rf_label): 
        $checked = ($selected_rf === $rf_label) ? 'checked' : '';
    ?>
        <div style="margin-bottom:6px;">
            <label style="font-weight:normal;">
                <input type="radio" 
                       name="rf_connector_type" 
                       value="<?php echo esc_attr($rf_label); ?>"
                       <?php echo $checked; ?>>
                <?php echo esc_html($rf_label); ?>
            </label>
        </div>
    <?php endforeach; ?>
  </td>

  <td style="text-align:center;">
    <input type="number"
           name="rf_connector_channels"
           min="0"
           value="<?php echo $rf_saved_channels; ?>"
           style="width:90px;height:38px;padding:8px 12px;border:1px solid #000;text-align:center;">
  </td>

  <td>
    <input class="block"
           name="rf_connector_comments"
           value="<?php echo esc_attr($rf_saved_comments); ?>">
  </td>
</tr>
<tr>
  <td>6</td>
  <td colspan="3">
    <strong style="display:block;margin-bottom:8px;">Special Requirement(s) if any</strong>
    <textarea name="special_requirements"
      placeholder="Enter any special requirements..."><?php echo esc_textarea($d->special_requirements ?? ''); ?></textarea>
  </td>
</tr>
</table>

<div style="text-align:right;margin-top:30px;display:flex;justify-content:flex-end;gap:15px;flex-wrap:wrap;">
  <?php if (!$resubmit): ?>
  <button type="submit" name="save_indenter_draft" class="btn btn-draft">&#128190; SAVE DRAFT</button>
  <?php endif; ?>
  <button type="submit" name="submit_request" class="btn-submit">
    <?php echo $resubmit ? '&#8617; RESUBMIT FOR QA REVIEW' : 'SUBMIT FOR APPROVAL'; ?>
  </button>
</div>
</form>

<script>
<?php if (!$resubmit): ?>
function toggleQA() {
    const qaFields = document.querySelectorAll('.qa_field');
    const qaSearch = document.getElementById('qa_search');
    const hasQA = document.querySelector('input[name="qa_exists"]:checked').value === 'yes';
    qaFields.forEach(function(f){ f.style.display = hasQA ? 'table-cell' : 'none'; });
    qaSearch.style.display = hasQA ? 'block' : 'none';
    if (!hasQA) {
        ['qa_name','qa_stno','qa_email','qa_section','qa_phone'].forEach(function(id){
            var el = document.getElementById(id); if(el) el.value = '';
        });
        document.getElementById('qa_stno_search').value = '';
    }
}
function fetchQAData() {
    const stno = document.getElementById('qa_stno_search').value.trim();
    if (!stno) { alert('Please enter Staff Number'); return; }
    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=fetch_employee&stno=' + encodeURIComponent(stno)
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if (data.success) {
            document.getElementById('qa_name').value    = data.data.name || '';
            document.getElementById('qa_stno').value    = data.data.stno || '';
            document.getElementById('qa_email').value   = data.data.email || '';
            document.getElementById('qa_section').value = data.data.sectionfullname || '';
            document.getElementById('qa_phone').value   = data.data.telephoneno || '';
        } else {
            alert('Employee not found! Please check the Staff Number.');
        }
    })
    .catch(function(){ alert('Error fetching employee data. Please try again.'); });
}
<?php endif; ?>
</script>
<?php }

// =====================================================================
//  SHARED: MANAGER TAB BAR
// =====================================================================
function mgr_tabs($active, $cnt_pending) {
    $base = get_permalink();
    $tabs = [
        ['label'=>'Dashboard',              'href'=>$base,                                                   'key'=>'dashboard'],
        ['label'=>"Pending ($cnt_pending)", 'href'=>add_query_arg('mgr_action','pending',$base),             'key'=>'pending'],
        ['label'=>'In Testing',             'href'=>add_query_arg('mgr_action','in_testing',$base),          'key'=>'in_testing'],
        ['label'=>'Rejected',               'href'=>add_query_arg('mgr_action','rejected_list',$base),       'key'=>'rejected_list'],
        ['label'=>'Completed',              'href'=>add_query_arg('mgr_action','completed_list',$base),      'key'=>'completed_list'],
        ['label'=>'My Requests',            'href'=>add_query_arg('mgr_action','my_requests',$base),         'key'=>'my_requests'],
    ];
    echo '<div class="mgr-tabs">';
    foreach($tabs as $t) {
        $cls = ($t['key']===$active) ? 'mgr-tab active' : 'mgr-tab';
        echo "<a href='".esc_url($t['href'])."' class='$cls'>".esc_html($t['label'])."</a>";
    }
    echo "<a href='".esc_url(add_query_arg('mgr_action','create_new'))."' class='mgr-tab mgr-tab-new'>+ NEW REQUEST</a>";
    echo '</div>';
}

// =====================================================================
//  SHARED: STAT CARDS
// =====================================================================
function mgr_stat_cards($cnt_pending, $cnt_approved, $cnt_rejected, $cnt_completed) {
    $base = get_permalink(); ?>
<div class="stat-grid">
  <a href="<?php echo esc_url(add_query_arg('mgr_action','pending',$base)); ?>" class="stat-card sc-pending" style="text-decoration:none;cursor:pointer;">
    <div class="stat-num"><?php echo $cnt_pending; ?></div><div class="stat-lbl">Pending Approval</div>
  </a>
  <a href="<?php echo esc_url(add_query_arg('mgr_action','in_testing',$base)); ?>" class="stat-card sc-approved" style="text-decoration:none;cursor:pointer;">
    <div class="stat-num"><?php echo $cnt_approved; ?></div><div class="stat-lbl">In Testing</div>
  </a>
  <a href="<?php echo esc_url(add_query_arg('mgr_action','rejected_list',$base)); ?>" class="stat-card" style="border-color:#dc3545;background:#fff5f5;color:#721c24;text-decoration:none;cursor:pointer;">
    <div class="stat-num"><?php echo $cnt_rejected; ?></div><div class="stat-lbl">Rejected</div>
  </a>
  <a href="<?php echo esc_url(add_query_arg('mgr_action','completed_list',$base)); ?>" class="stat-card" style="border-color:#000;background:#f8f8f8;color:#000;text-decoration:none;cursor:pointer;">
    <div class="stat-num"><?php echo $cnt_completed; ?></div><div class="stat-lbl">Completed</div>
  </a>
</div>
<?php }

// =====================================================================
//  PIPELINE HELPER - GET EXTENDED PIPELINE STEPS (CONDITIONAL QA)
// =====================================================================
// Returns extended pipeline steps with conditional QA logic
// Parameters: $req - database record, $qa_required - 'yes'/'no'

function lssc_get_extended_pipeline_steps($req, $qa_required = null) {
    // Determine QA requirement from database if not passed
    if ($qa_required === null) {
        $qa_required = strtolower($req->qa_exists ?? 'no');
    } else {
        $qa_required = strtolower($qa_required);
    }
    
    // Base pipeline: Submitted (always)
    $steps = [
        [
            'label' => 'Submitted',
            'done'  => true,
            'date'  => $req->submission_date,
            'stage' => 'submitted'
        ]
    ];
    
    // CONDITIONAL: QA Review step only if qa_required = 'yes'
    if ($qa_required === 'yes') {
        $steps[] = [
            'label' => 'QA Review',
            'done'  => in_array($req->status, ['pending', 'approved', 'completed', 'in_testing']),
            'date'  => $req->qa_review_date ?? null,
            'stage' => 'qa_review'
        ];
    }
    
    // Manager Approved
    $steps[] = [
        'label' => 'Mgr Approved',
        'done'  => in_array($req->status, ['approved', 'completed', 'in_testing']),
        'date'  => $req->approval_date ?? null,
        'stage' => 'mgr_approved'
    ];
    
    // Extended pipeline: Chamber Occupied → Test Started → Test Completed → Chamber Vacated
    // Trigger when status moves to 'approved' or beyond
    if (in_array($req->status, ['approved', 'completed', 'in_testing'])) {
        
        // Chamber Occupied: Assume occupied when test_started_datetime is filled
        $steps[] = [
            'label' => 'Chamber Occupied',
            'done'  => !empty($req->test_started_datetime),
            'date'  => !empty($req->test_started_datetime) ? $req->test_started_datetime : null,
            'stage' => 'chamber_occupied'
        ];
        
        // Test Started: When test_started_datetime is filled
        $steps[] = [
            'label' => 'Test Started',
            'done'  => !empty($req->test_started_datetime),
            'date'  => !empty($req->test_started_datetime) ? $req->test_started_datetime : null,
            'stage' => 'test_started'
        ];
        
        // Test Completed: When test_completed_datetime is filled
        $steps[] = [
            'label' => 'Test Completed',
            'done'  => !empty($req->test_completed_datetime),
            'date'  => !empty($req->test_completed_datetime) ? $req->test_completed_datetime : null,
            'stage' => 'test_completed'
        ];
        
        // Chamber Vacated: When completion_date is set AND status === 'completed' or 'Completed'
        $steps[] = [
            'label' => 'Chamber Vacated',
            'done'  => in_array($req->status, ['completed', 'Completed']),
            'date'  => in_array($req->status, ['completed', 'Completed']) ? $req->completion_date : null,
            'stage' => 'chamber_vacated'
        ];
    }
    
    return $steps;
}

// =====================================================================
//  PIPELINE RENDERER (WITH PROPER DATE FORMATTING)
// =====================================================================
// Renders the progress pipeline with proper date/time formatting
// Timezone: Asia/Kolkata

function lssc_pipeline($steps) {
    echo '<div style="display:flex;align-items:flex-start;gap:20px;margin:30px 0;flex-wrap:wrap;">';
    $last_done = -1;
    foreach ($steps as $i => $s) { if ($s['done']) $last_done = $i; }
    foreach ($steps as $i => $s) {
        $done   = $s['done'];
        $active = !$done && ($i === $last_done + 1);
        $bg     = $done ? '#28a745' : ($active ? '#ffc107' : '#e0e0e0');
        $co     = $done ? '#fff' : ($active ? '#000' : '#999');
        $icon   = $done ? '✓' : ($active ? '●' : '○');
        $sub    = '';
        
        // Format date/time with proper timezone handling
        if ($done && !empty($s['date'])) {
            // Parse datetime string and format with proper timezone
            try {
                $dt = new DateTime($s['date'], new DateTimeZone('UTC'));
                $dt->setTimeZone(new DateTimeZone('Asia/Kolkata'));
                $sub = $dt->format('d M, h:i A');
            } catch (Exception $e) {
                // Fallback if DateTime parsing fails
                $ts = strtotime($s['date']);
                if ($ts) {
                    // Apply timezone offset for Asia/Kolkata (UTC+5:30)
                    $date_obj = new DateTime('@' . $ts);
                    $date_obj->setTimeZone(new DateTimeZone('Asia/Kolkata'));
                    $sub = $date_obj->format('d M, h:i A');
                } else {
                    $sub = esc_html($s['date']);
                }
            }
        }
        elseif ($active) $sub = 'In Progress';
        else             $sub = 'Waiting';
        
        echo "<div style='flex:1;text-align:center;min-width:140px;'>";
        echo "<div style='width:40px;height:40px;border-radius:50%;background:".esc_attr($bg).";color:".esc_attr($co).";display:flex;align-items:center;justify-content:center;margin:0 auto 10px;font-size:16px;font-weight:bold;border:2px solid ".esc_attr($bg).";'>".esc_html($icon)."</div>";
        echo "<div style='font-size:12px;font-weight:600;text-transform:uppercase;color:#333;'>".esc_html($s['label'])."</div>";
        echo "<div style='font-size:11px;color:#888;margin-top:3px;'>".esc_html($sub)."</div>";
        echo "</div>";
    }
    echo '</div>';
}

// =====================================================================
//  INDENTER VIEW
// =====================================================================
if ($user_role === 'indenter') {
    $action  = $_GET['action'] ?? 'dashboard';
    $view_id = intval($_GET['view_id'] ?? 0);

    

    if ($view_id) {
        $req = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d AND user_id=%d", $view_id, $user->ID));
        if ($req):
            // Use new extended pipeline function with conditional QA logic
            $qa_required = strtolower($req->qa_exists ?? 'no');
            $steps = lssc_get_extended_pipeline_steps($req, $qa_required);
            $bc='badge-pending';
            if($req->status==='pending_qa')      $bc='badge-pending-qa';
            if($req->status==='qa_rejected')     $bc='badge-qa-rejected';
            if($req->status==='approved')        $bc='badge-approved';
            if($req->status==='rejected')        $bc='badge-rejected';
            if($req->status==='recheck_required') $bc='badge-recheck-required';
            if($req->status==='completed')       $bc='badge-completed';
?>
<div class="container">
<div class="role-indicator">INDENTER VIEW | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>
<div style="margin-bottom:25px;"><a href="<?php echo get_permalink(); ?>" class="btn btn-primary">← Back to My Requests</a></div>
<div class="request-card">
  <div class="request-header">
    <div>
      <h2 style="margin:0;font-size:22px;">TR No: <?php echo esc_html($req->test_requisition_no); ?></h2>
      <small style="color:#666;"><?php echo esc_html($req->satellite_name.' | '.$req->project_program); ?></small><br>
      <small style="color:#666;">Submitted: <?php echo date('d M Y, h:i A', strtotime($req->submission_date)); ?></small>
    </div>
    <span class="badge <?php echo $bc; ?>"><?php echo strtoupper($req->status); ?></span>
  </div>

  <h3 style="margin-top:0;">Live Progress Pipeline</h3>
  <?php lssc_pipeline($steps); ?>

  <h3>Request Details</h3>
  <table>
    <tr><th style="width:20%">Satellite/Test Object</th><th style="width:30%"><?php echo esc_html($req->satellite_name); ?></th><th style="width:20%">Test Type</th><td><?php echo esc_html($req->test_type); ?></td></tr>
    <tr><th>Project/Program</th><td><?php echo esc_html($req->project_program); ?></td><th>Test Required on</th><td><?php echo !empty($req->test_required_on) ? date('d M Y', strtotime($req->test_required_on)) : '—'; ?></td></tr>
    <tr><th>Subsystem Eng.</th><td><?php echo esc_html($req->sub_name.' ('.$req->sub_stno.')'); ?></td><th>Section</th><td><?php echo esc_html($req->sub_section); ?></td></tr>
    <tr><th>Designation</th><td><?php echo esc_html($req->sub_designation ?: '—'); ?></td><th>Phone</th><td><?php echo esc_html($req->sub_phone ?: '—'); ?></td></tr>
    <?php if($req->qa_exists === 'yes' && !empty($req->qa_name)): ?>
    <tr>
      <th>QA / T&amp;E Engineer</th>
      <td colspan="3"><?php echo esc_html($req->qa_name . ' (' . $req->qa_stno . ')'); ?><br>
        <small style="color:#555;"><?php echo esc_html($req->qa_section); ?></small><br>
        <small style="color:#555;">Tel: <?php echo esc_html($req->qa_phone); ?></small>
      </td>
    </tr>
    <?php endif; ?>
    <?php if(!empty($req->special_requirements)):?>
    <tr><th>Special Req.</th><td colspan="3"><?php echo nl2br(esc_html($req->special_requirements)); ?></td></tr>
    <?php endif;?>
  </table>

  <h3>Chamber Interface Requirements</h3>
  <table>
    <tr><th style="width:5%">Sl.</th><th style="width:40%">Description</th><th style="width:20%">No. of Channels</th><th>Comments</th></tr>
    <tr><td>1</td><td>Thermal Power Channels</td><td><?php echo intval($req->thermal_power); ?></td><td><?php echo esc_html($req->thermal_power_comments ?: '—'); ?></td></tr>
    <tr><td>2</td><td>Thermal Thermocouples</td><td><?php echo intval($req->thermal_thermocouples); ?></td><td><?php echo esc_html($req->thermal_thermocouples_comments ?: '—'); ?></td></tr>
    <tr><td>3</td><td>Ground Checkout DC Signal Lines</td><td><?php echo intval($req->ground_dc_signal); ?></td><td><?php echo esc_html($req->ground_dc_signal_comments ?: '—'); ?></td></tr>
    <tr><td>4</td><td>Ground Signal Power Lines</td><td><?php echo intval($req->ground_signal_power); ?></td><td><?php echo esc_html($req->ground_signal_power_comments ?: '—'); ?></td></tr>
    <tr><td>5</td><td>Ground Checkout RF Connectors<br><small style="color:#555;font-weight:normal;"><?php echo esc_html($req->rf_connector_type ?: '—'); ?></small></td><td><?php echo intval($req->rf_connector_channels); ?></td><td><?php echo esc_html($req->rf_connector_comments ?: '—'); ?></td></tr>
  </table>

  <?php if (!empty($req->qa_reviewer_name)): ?>
  <h3>QA / T&amp;E Engineer Review</h3>
  <table>
    <tr><th style="width:20%">Reviewed By</th><td style="width:30%"><?php echo esc_html($req->qa_reviewer_name ?: '—'); ?></td><th style="width:20%">Review Date</th><td><?php echo !empty($req->qa_review_date) ? date('d M Y, h:i A', strtotime($req->qa_review_date)) : '—'; ?></td></tr>
    <tr><th>Decision</th><td><?php echo $req->qa_decision === 'accept' ? '<span style="color:#28a745;font-weight:600;">✓ Accepted</span>' : '<span style="color:#fd7e14;font-weight:600;">✗ Rejected</span>'; ?></td><th>Remarks</th><td><?php echo esc_html($req->qa_remarks ?: '—'); ?></td></tr>
  </table>
  <?php endif; ?>
  <?php if ($req->status === 'qa_rejected'): ?>
  <div style="background:#fff3cd;border:2px solid #fd7e14;padding:18px 22px;margin:20px 0;border-radius:4px;">
    <strong style="color:#856404;">⚠ Your request was returned by the QA Engineer.</strong><br>
    <span style="font-size:14px;color:#555;">Reason: <?php echo esc_html($req->qa_remarks ?: '—'); ?></span><br><br>
    <a href="<?php echo esc_url(add_query_arg(['action'=>'create_new','resume_draft'=>$req->id], remove_query_arg(['view_id'], get_permalink()))); ?>" class="btn btn-draft">✏ Edit &amp; Resubmit</a>
  </div>
  <?php endif; ?>
  <?php if ($req->status === 'rejected'): ?>
  <div style="background:#f8d7da;border:2px solid #dc3545;padding:18px 22px;margin:20px 0;border-radius:4px;">
    <strong style="color:#721c24;">✗ Your request was rejected by the Manager.</strong><br>
    <span style="font-size:14px;color:#555;">Reason: <?php echo esc_html($req->manager_comment ?: '—'); ?></span><br><br>
    <a href="<?php echo esc_url(add_query_arg(['action'=>'create_new','resume_draft'=>$req->id], remove_query_arg(['view_id'], get_permalink()))); ?>" class="btn btn-draft">✏ Edit &amp; Resubmit</a>
  </div>
  <?php endif; ?>
  <?php if (!empty($req->manager_id) || in_array($req->status, ['approved','rejected','completed'])): 
    // Get manager details from manager_id
    $mgr_user = get_userdata($req->manager_id);
    $mgr_name = $mgr_user ? $mgr_user->display_name : '—';
    
    // Format approval date with proper timezone
    $approval_display = '';
    if (!empty($req->approval_date)) {
        try {
            $dt = new DateTime($req->approval_date, new DateTimeZone('UTC'));
            $dt->setTimeZone(new DateTimeZone('Asia/Kolkata'));
            $approval_display = $dt->format('d M Y, h:i A');
        } catch (Exception $e) {
            $approval_display = date('d M Y, h:i A', strtotime($req->approval_date));
        }
    } else {
        $approval_display = '—';
    }
  ?>
  <h3>Manager Review</h3>
  <table>
    <tr>
      <th style="width:20%">Reviewed By</th>
      <td style="width:30%"><?php echo esc_html($mgr_name); ?></td>
      <th style="width:20%">Approved On</th>
      <td><?php echo esc_html($approval_display); ?></td>
    </tr>
    <tr>
      <th>Decision</th>
      <td colspan="3">
        <?php 
          $action_badge = '';
          if (in_array($req->manager_action, ['approve','approved'])) {
            $action_badge = '<span style="background:#28a745;color:#fff;padding:4px 12px;border-radius:3px;font-size:12px;font-weight:600;">✓ APPROVED</span>';
          } elseif (in_array($req->manager_action, ['reject','rejected'])) {
            $action_badge = '<span style="background:#dc3545;color:#fff;padding:4px 12px;border-radius:3px;font-size:12px;font-weight:600;">✗ REJECTED</span>';
          } elseif ($req->manager_action === 'recheck') {
            $action_badge = '<span style="background:#ffc107;color:#000;padding:4px 12px;border-radius:3px;font-size:12px;font-weight:600;">⟲ RECHECK REQUIRED</span>';
          }
          echo $action_badge ?: '—';
        ?>
      </td>
    </tr>
    <?php if (!empty($req->manager_final_comment)): ?>
    <tr>
      <th>Manager Comments</th>
      <td colspan="3"><?php echo nl2br(esc_html($req->manager_final_comment)); ?></td>
    </tr>
    <?php endif; ?>
  </table>

  <h3 style="margin-top:18px;">Test Environmental Requirements</h3>
  <table style="width:100%;border-collapse:collapse;">
    <tr>
      <th style="border:1px solid #000;padding:12px;text-align:left;background:#f5f5f5;width:5%">Sl</th>
      <th style="border:1px solid #000;padding:12px;text-align:left;background:#f5f5f5;width:25%">Description</th>
      <th style="border:1px solid #000;padding:12px;text-align:left;background:#f5f5f5;width:35%">Specifications</th>
      <th style="border:1px solid #000;padding:12px;text-align:left;background:#f5f5f5;width:35%">Manager Comments</th>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:12px;">1</td>
      <td style="border:1px solid #000;padding:12px;">Vacuum</td>
      <td style="border:1px solid #000;padding:12px;"><?php echo esc_html($req->env_vacuum ?: '—'); ?></td>
      <td style="border:1px solid #000;padding:12px;"><?php echo nl2br(esc_html($req->env_vacuum_mgr_comment ?: '—')); ?></td>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:12px;">2</td>
      <td style="border:1px solid #000;padding:12px;">Shroud Temperature</td>
      <td style="border:1px solid #000;padding:12px;"><?php echo esc_html($req->env_shroud_temp ?: '—'); ?></td>
      <td style="border:1px solid #000;padding:12px;"><?php echo nl2br(esc_html($req->env_shroud_temp_mgr_comment ?: '—')); ?></td>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:12px;">3</td>
      <td style="border:1px solid #000;padding:12px;">Solar Beam Intensity</td>
      <td style="border:1px solid #000;padding:12px;"><?php echo esc_html($req->env_solar_beam ?: '—'); ?></td>
      <td style="border:1px solid #000;padding:12px;"><?php echo nl2br(esc_html($req->env_solar_beam_mgr_comment ?: '—')); ?></td>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:12px;">4</td>
      <td style="border:1px solid #000;padding:12px;">Eclipse Details</td>
      <td style="border:1px solid #000;padding:12px;"><?php echo esc_html($req->env_eclipse ?: '—'); ?></td>
      <td style="border:1px solid #000;padding:12px;"><?php echo nl2br(esc_html($req->env_eclipse_mgr_comment ?: '—')); ?></td>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:12px;">5</td>
      <td style="border:1px solid #000;padding:12px;">Motion Simulator / Tilt</td>
      <td style="border:1px solid #000;padding:12px;"><?php echo esc_html($req->env_motion_tilt ?: '—'); ?></td>
      <td style="border:1px solid #000;padding:12px;"><?php echo nl2br(esc_html($req->env_motion_tilt_mgr_comment ?: '—')); ?></td>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:12px;">6</td>
      <td style="border:1px solid #000;padding:12px;">Motion Simulator / Spin</td>
      <td style="border:1px solid #000;padding:12px;"><?php echo esc_html($req->env_motion_spin ?: '—'); ?></td>
      <td style="border:1px solid #000;padding:12px;"><?php echo nl2br(esc_html($req->env_motion_spin_mgr_comment ?: '—')); ?></td>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:12px;">7</td>
      <td style="border:1px solid #000;padding:12px;">Motion Simulator / Speed</td>
      <td style="border:1px solid #000;padding:12px;"><?php echo esc_html($req->env_motion_speed ?: '—'); ?></td>
      <td style="border:1px solid #000;padding:12px;"><?php echo nl2br(esc_html($req->env_motion_speed_mgr_comment ?: '—')); ?></td>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:12px;">8</td>
      <td style="border:1px solid #000;padding:12px;">Mechanical Interface</td>
      <td style="border:1px solid #000;padding:12px;"><?php echo esc_html($req->env_mechanical ?: '—'); ?></td>
      <td style="border:1px solid #000;padding:12px;"><?php echo nl2br(esc_html($req->env_mechanical_mgr_comment ?: '—')); ?></td>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:12px;">9</td>
      <td style="border:1px solid #000;padding:12px;">Special Requirements</td>
      <td style="border:1px solid #000;padding:12px;"><?php echo esc_html($req->env_special_req ?: '—'); ?></td>
      <td style="border:1px solid #000;padding:12px;"><?php echo nl2br(esc_html($req->env_special_req_mgr_comment ?: '—')); ?></td>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:12px;">10</td>
      <td style="border:1px solid #000;padding:12px;">Key Characteristics</td>
      <td style="border:1px solid #000;padding:12px;"><?php echo esc_html($req->env_key_char ?: '—'); ?></td>
      <td style="border:1px solid #000;padding:12px;"><?php echo nl2br(esc_html($req->env_key_char_mgr_comment ?: '—')); ?></td>
    </tr>
  </table>
  <?php endif; ?>

</div>
</div>
<?php
        else: echo "<p style='text-align:center;color:#dc3545;padding:50px;'>Request not found.</p>"; endif;

    } elseif ($action === 'create_new') {
        // Resume specific draft if requested, or latest draft
        $resume_id = intval($_GET['resume_draft'] ?? 0);
        if ($resume_id) {
            $existing_draft = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id=%d AND user_id=%d AND status IN ('draft_indenter','qa_rejected','rejected','recheck_required')",
                $resume_id, $user->ID
            ));
        } else {
            $existing_draft = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id=%d AND status='draft_indenter' ORDER BY indenter_draft_saved_at DESC LIMIT 1",
                $user->ID
            ));
        } ?>
<div class="form-container">
<div class="role-indicator">INDENTER VIEW | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>
<div style="margin-bottom:25px;"><a href="<?php echo get_permalink(); ?>" class="btn btn-primary">← Back to Dashboard</a></div>
<h1>New Request Submission</h1>
<?php
// Show validation errors if any
$_lssc_errs = get_transient('lssc_errors_'.$user->ID);
if (!empty($_lssc_errs)) {
    delete_transient('lssc_errors_'.$user->ID);
    echo "<div style='background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;padding:14px 20px;margin-bottom:20px;border-radius:4px;font-size:15px;'>";
    echo "<strong>Please fix the following errors:</strong><ul style='margin:8px 0 0 20px;padding:0;'>";
    foreach ($_lssc_errs as $e) echo "<li>".esc_html($e)."</li>";
    echo "</ul></div>";
}
?>
<?php lssc_request_form($emp, $existing_draft, admin_url('admin-ajax.php')); ?>
</div>
<?php
    } else {
        // Fetch ALL records for this user regardless of status
        $all_my = $wpdb->get_results($wpdb->prepare(
          "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC",
          (int)$user->ID
        ));
        // Latest approved request for this user (used to show TEST DETAILS button)
        $latest_approved_id = $wpdb->get_var($wpdb->prepare(
          "SELECT id FROM {$table} WHERE user_id = %d AND status = %s ORDER BY submission_date DESC LIMIT 1",
          (int)$user->ID, 'approved'
        ));
        // Fallback: also try matching by email in case user_id was stored differently
        if (empty($all_my)) {
            $all_my = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE sub_email = %s ORDER BY id DESC",
                $user->user_email
            ));
        }
        $my_drafts   = array_filter((array)$all_my, fn($r) => $r->status === 'draft_indenter');
        $my_requests = array_filter((array)$all_my, fn($r) => $r->status !== 'draft_indenter');
        $has_approved = !empty($latest_approved_id);
        $lssc_msg    = sanitize_text_field($_GET['lssc_msg'] ?? ''); ?>
<div class="container">
<div class="role-indicator">INDENTER VIEW | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>

<?php if ($lssc_msg === 'draft_saved'): ?>
<div style="background:#d1ecf1;color:#0c5460;border:1px solid #bee5eb;padding:16px 22px;margin-bottom:25px;border-radius:4px;font-size:15px;">
  &#10003; <strong>Draft saved successfully.</strong> You can continue editing it below.
</div>
<?php elseif ($lssc_msg === 'submitted'): ?>
<div style="background:#d4edda;color:#155724;border:1px solid #c3e6cb;padding:16px 22px;margin-bottom:25px;border-radius:4px;font-size:15px;">
  &#10003; <strong>Request submitted successfully.</strong> A Test Requisition Number will be assigned upon manager approval.
</div>
<?php endif; ?>

<h1>My LSSC Requests</h1>
<div style="margin-bottom:25px;display:flex;justify-content:space-between;align-items:center;gap:12px;">
  <a href="<?php echo add_query_arg('action','create_new'); ?>" class="btn btn-success">+ CREATE NEW REQUEST</a>
  <?php if (!empty($has_approved)): ?>
    <a href="<?php echo esc_url(add_query_arg(['action'=>'staff_dashboard','request_id'=>$latest_approved_id], get_permalink())); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-test-details">TEST DETAILS</a>
  <?php endif; ?>
</div>

<?php if (current_user_can('administrator') || isset($_GET['lssc_debug'])): ?>
<div style="background:#f8f9fa;border:1px solid #ccc;padding:12px 16px;margin-bottom:20px;font-size:12px;font-family:monospace;border-radius:4px;">
  <strong>DEBUG</strong> | user_id=<?php echo $user->ID; ?> | email=<?php echo esc_html($user->user_email); ?> | table=<?php echo esc_html($table); ?><br>
  Total records found: <?php echo count($all_my); ?> | Drafts: <?php echo count($my_drafts); ?> | Submitted: <?php echo count($my_requests); ?><br>
  Last DB error: <?php echo esc_html($wpdb->last_error ?: 'none'); ?><br>
  Last query: <?php echo esc_html($wpdb->last_query); ?>
</div>
<?php endif; ?>

<?php if (!empty($my_drafts)): ?>
<h3 style="margin-bottom:12px;">&#128203; Saved Drafts</h3>
<table class="list-table" style="margin-bottom:35px;">
  <thead><tr><th>Satellite/Test Object</th><th>Project</th><th>Last Saved</th><th>Action</th></tr></thead>
  <tbody>
  <?php foreach($my_drafts as $dr): ?>
  <tr style="background:#fffdf0;">
    <td><?php echo esc_html($dr->satellite_name ?: '(Untitled)'); ?></td>
    <td><?php echo esc_html($dr->project_program ?: '—'); ?></td>
    <td><?php echo !empty($dr->indenter_draft_saved_at) ? date('d M Y, h:i A', strtotime($dr->indenter_draft_saved_at)) : '—'; ?></td>
    <td>
      <a href="<?php echo esc_url(add_query_arg(['action'=>'create_new','resume_draft'=>$dr->id])); ?>" class="btn btn-draft" style="margin-right:8px;">Continue Draft</a>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php if(empty($my_requests)): ?>
<div style="text-align:center;padding:60px;color:#666;border:2px solid #ddd;background:#f9f9f9;border-radius:8px;">
  <h3 style="margin:0 0 10px 0;font-size:18px;color:#333;">NO SUBMITTED REQUESTS YET</h3>
  <p style="margin:0;font-size:15px;"><?php echo !empty($my_drafts) ? 'You have saved drafts above. Complete and submit them for approval.' : 'Click "+ CREATE NEW REQUEST" to fill your first LSSC form.'; ?></p>
</div>
<?php else: ?>

<h3 style="margin-bottom:12px;">Submitted Requests</h3>
<table class="list-table">
  <thead>
    <tr>
      <th>TR No.</th>
      <th>Satellite/Test Object</th>
      <th>Project</th>
      <th>Submitted Date</th>
      <th>Status</th>
      <th>Action</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach($my_requests as $req): 
    $bc='badge-pending';
    if($req->status==='pending_qa')       $bc='badge-pending-qa';
    if($req->status==='qa_rejected')      $bc='badge-qa-rejected';
    if($req->status==='approved')         $bc='badge-approved';
    if($req->status==='rejected')         $bc='badge-rejected';
    if($req->status==='recheck_required') $bc='badge-recheck-required';
    if($req->status==='completed')        $bc='badge-completed';
    // Show TR no only if assigned (approved), otherwise show 'Awaiting Approval'
    $tr_display = (strpos($req->test_requisition_no, 'PENDING-') === 0 || strpos($req->test_requisition_no, 'DRAFT-') === 0)
        ? '<em style="color:#999;font-size:12px;">Awaiting Approval</em>'
        : '<strong>'.esc_html($req->test_requisition_no).'</strong>';
  ?>
  <tr>
    <td><?php echo $tr_display; ?></td>
    <td><?php echo esc_html($req->satellite_name); ?></td>
    <td><?php echo esc_html($req->project_program); ?></td>
    <td><?php echo date('d M Y, h:i A', strtotime($req->submission_date)); ?></td>
    <td><span class="badge <?php echo $bc; ?>"><?php echo strtoupper($req->status); ?></span></td>
    <td>
      <a href="<?php echo add_query_arg('view_id',$req->id); ?>" class="btn btn-view">View Details</a>
      <?php if(in_array($req->status, ['qa_rejected','rejected','recheck_required'])): ?>
      <a href="<?php echo esc_url(add_query_arg(['action'=>'create_new','resume_draft'=>$req->id], get_permalink())); ?>"
         class="btn btn-draft" style="margin-left:8px;">✏ Edit &amp; Resubmit</a>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
</div>
<?php
    }

// =====================================================================
//  QA ENGINEER VIEW
// =====================================================================
} elseif ($user_role === 'qa_engineer') {

    $qa_view_id = intval($_GET['qa_view'] ?? 0);
    $lssc_msg   = sanitize_text_field($_GET['lssc_msg'] ?? '');

    // Counts
    $cnt_qa_pending  = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='pending_qa'");
    $cnt_qa_accepted = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status IN ('pending','approved','completed') AND qa_decision='accept'");
    $cnt_qa_rejected = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='qa_rejected'");
    $cnt_qa_all      = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status NOT IN ('draft_indenter')");

    if ($qa_view_id) {
        // ── QA REVIEW PAGE ────────────────────────────────────────────────────
        $req = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id=%d AND status='pending_qa'", $qa_view_id
        ));
        if ($req): ?>
<div class="container">
<div class="role-indicator">QA / T&amp;E ENGINEER VIEW | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>
<div style="margin-bottom:25px;">
  <a href="<?php echo get_permalink(); ?>" class="btn btn-primary">← Back to Dashboard</a>
</div>

<div class="request-card">
  <div class="request-header">
    <div>
      <h2 style="margin:0;font-size:22px;">Review Request — <?php echo esc_html($req->test_requisition_no); ?></h2>
      <small style="color:#666;"><?php echo esc_html($req->satellite_name.' | '.$req->project_program); ?></small><br>
      <small style="color:#666;">Submitted by <strong><?php echo esc_html($req->sub_name); ?></strong> on <?php echo date('d M Y, h:i A', strtotime($req->submission_date)); ?></small>
    </div>
    <span class="badge badge-pending-qa">PENDING QA REVIEW</span>
  </div>

  <h3>Submitter Details</h3>
  <table>
    <tr>
      <th style="width:20%">Name</th><td style="width:30%"><?php echo esc_html($req->sub_name); ?></td>
      <th style="width:20%">Staff No.</th><td><?php echo esc_html($req->sub_stno); ?></td>
    </tr>
    <tr>
      <th>Designation</th><td><?php echo esc_html($req->sub_designation ?: '—'); ?></td>
      <th>Phone</th><td><?php echo esc_html($req->sub_phone ?: '—'); ?></td>
    </tr>
    <tr>
      <th>Section / Division</th><td colspan="3"><?php echo esc_html(($req->sub_section ?: '—').' / '.($req->sub_division ?: '—')); ?></td>
    </tr>
    <?php if ($req->qa_exists === 'yes' && !empty($req->qa_name)): ?>
    <tr>
      <th>QA / T&amp;E Engineer (Nominated)</th>
      <td colspan="3"><?php echo esc_html($req->qa_name.' ('.$req->qa_stno.')'); ?> — <small><?php echo esc_html($req->qa_section); ?></small></td>
    </tr>
    <?php endif; ?>
  </table>

  <h3>Test Object Details</h3>
  <table>
    <tr>
      <th style="width:25%">Satellite/Test Object</th><td style="width:25%"><?php echo esc_html($req->satellite_name); ?></td>
      <th style="width:25%">Test Type</th><td><?php echo esc_html($req->test_type); ?></td>
    </tr>
    <tr>
      <th>Project / Program</th><td><?php echo esc_html($req->project_program); ?></td>
      <th>Test Required on</th><td><?php echo !empty($req->test_required_on) ? date('d M Y', strtotime($req->test_required_on)) : '—'; ?></td>
    </tr>
    <?php if (!empty($req->special_requirements)): ?>
    <tr><th>Special Requirements</th><td colspan="3" style="background:#fff3cd;"><?php echo nl2br(esc_html($req->special_requirements)); ?></td></tr>
    <?php endif; ?>
  </table>

  <h3>Chamber Interface Requirements</h3>
  <table>
    <tr>
      <th style="width:5%">Sl.</th>
      <th style="width:40%">Description</th>
      <th style="width:20%">No. of Channels</th>
      <th>Comments</th>
    </tr>
    <tr><td>1</td><td>Thermal Power Channels</td><td><?php echo intval($req->thermal_power); ?></td><td><?php echo esc_html($req->thermal_power_comments ?: '—'); ?></td></tr>
    <tr><td>2</td><td>Thermal Thermocouples</td><td><?php echo intval($req->thermal_thermocouples); ?></td><td><?php echo esc_html($req->thermal_thermocouples_comments ?: '—'); ?></td></tr>
    <tr><td>3</td><td>Ground Checkout DC Signal Lines</td><td><?php echo intval($req->ground_dc_signal); ?></td><td><?php echo esc_html($req->ground_dc_signal_comments ?: '—'); ?></td></tr>
    <tr><td>4</td><td>Ground Signal Power Lines</td><td><?php echo intval($req->ground_signal_power); ?></td><td><?php echo esc_html($req->ground_signal_power_comments ?: '—'); ?></td></tr>
    <tr>
      <td>5</td>
      <td>Ground Checkout RF Connectors<br><small style="color:#555;font-weight:normal;"><?php echo esc_html($req->rf_connector_type ?: '—'); ?></small></td>
      <td><?php echo intval($req->rf_connector_channels); ?></td>
      <td><?php echo esc_html($req->rf_connector_comments ?: '—'); ?></td>
    </tr>
  </table>

  <!-- QA REVIEW FORM -->
  <form method="post" style="margin-top:30px;">
    <?php wp_nonce_field('lssc_action','lssc_nonce'); ?>
    <input type="hidden" name="form_id" value="<?php echo intval($req->id); ?>">

    <div style="border:2px solid #6f42c1;padding:28px;background:#faf7ff;border-radius:6px;">
      <h4 style="margin:0 0 20px 0;font-size:16px;font-weight:600;text-transform:uppercase;letter-spacing:.8px;color:#6f42c1;">QA / T&amp;E Engineer Review</h4>

      <table style="width:100%;border-collapse:collapse;margin-bottom:20px;">
        <tr>
          <td style="border:1px solid #ddd;padding:12px;background:#f5f5f5;font-weight:600;width:25%;">Reviewer Name</td>
          <td style="border:1px solid #ddd;padding:12px;" colspan="3"><input class="block" value="<?php echo esc_attr($emp->name); ?>" readonly style="background:#f5f5f5;max-width:350px;"></td>
        </tr>
        <tr>
          <td style="border:1px solid #ddd;padding:12px;background:#f5f5f5;font-weight:600;">Review Date</td>
          <td style="border:1px solid #ddd;padding:12px;" colspan="3"><input class="block" value="<?php echo date('d M Y, h:i A'); ?>" readonly style="background:#f5f5f5;max-width:250px;"></td>
        </tr>
        <tr>
          <td style="border:1px solid #ddd;padding:14px;background:#f5f5f5;font-weight:600;">Remarks / Observations <span style="color:#dc3545;">*</span></td>
          <td style="border:1px solid #ddd;padding:12px;" colspan="3">
            <textarea name="qa_remarks" rows="4" placeholder="Enter your review remarks, observations, or reason for rejection..." required style="width:100%;border:1px solid #6f42c1;padding:12px;font-size:14px;font-family:inherit;resize:vertical;border-radius:4px;"></textarea>
          </td>
        </tr>
      </table>

      <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
        <button type="submit" name="qa_decision" value="accept"
          class="btn btn-approve" style="padding:14px 36px;font-size:14px;"
          onclick="return confirm('Accept this request and forward to Manager?')">
          ✓ ACCEPT &amp; FORWARD TO MANAGER
        </button>
        <button type="submit" name="qa_decision" value="reject"
          class="btn btn-reject" style="padding:14px 36px;font-size:14px;"
          onclick="return confirm('Reject and return this request to the Indenter?')">
          ✗ REJECT &amp; RETURN TO INDENTER
        </button>
      </div>
      <p style="font-size:13px;color:#666;margin-top:14px;">
        <strong>Accept</strong> → Request forwarded to Manager for approval &nbsp;|&nbsp;
        <strong>Reject</strong> → Request returned to Indenter with your remarks
      </p>
    </div>
  </form>
</div>
</div>

<?php
        else:
            echo "<p style='text-align:center;color:#dc3545;padding:50px;'>Request not found or already reviewed.</p>";
        endif;

    } else {
        // ── QA DASHBOARD ───────────────────────────────────────────────────────
        $pending_qa = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE status='pending_qa' ORDER BY submission_date ASC"
        );
        $reviewed = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE qa_decision != '' AND qa_decision IS NOT NULL ORDER BY qa_review_date DESC LIMIT 20"
        );
?>
<div class="container">
<div class="role-indicator">QA / T&amp;E ENGINEER VIEW | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>

<?php if ($lssc_msg === 'qa_accepted'): ?>
<div style="background:#d4edda;color:#155724;border:1px solid #c3e6cb;padding:14px 20px;margin-bottom:20px;border-radius:4px;">
  ✓ <strong>Request accepted and forwarded to Manager for approval.</strong>
</div>
<?php elseif ($lssc_msg === 'qa_rejected'): ?>
<div style="background:#fff3cd;color:#856404;border:1px solid #ffc107;padding:14px 20px;margin-bottom:20px;border-radius:4px;">
  ✓ <strong>Request rejected and returned to the Indenter with your remarks.</strong>
</div>
<?php endif; ?>

<!-- STAT CARDS -->
<div class="stat-grid" style="grid-template-columns:repeat(4,1fr);">
  <div class="stat-card" style="border-color:#6f42c1;background:#faf7ff;color:#6f42c1;">
    <div class="stat-num"><?php echo $cnt_qa_pending; ?></div>
    <div class="stat-lbl">Awaiting Review</div>
  </div>
  <div class="stat-card sc-approved">
    <div class="stat-num"><?php echo $cnt_qa_accepted; ?></div>
    <div class="stat-lbl">Accepted</div>
  </div>
  <div class="stat-card" style="border-color:#fd7e14;background:#fff8f0;color:#7d3c00;">
    <div class="stat-num"><?php echo $cnt_qa_rejected; ?></div>
    <div class="stat-lbl">Rejected</div>
  </div>
  <div class="stat-card" style="border-color:#000;background:#f8f8f8;color:#000;">
    <div class="stat-num"><?php echo $cnt_qa_all; ?></div>
    <div class="stat-lbl">Total Requests</div>
  </div>
</div>

<!-- PENDING REVIEW TABLE -->
<h3 style="margin-top:10px;">&#9881; Requests Awaiting Your Review (<?php echo count($pending_qa); ?>)</h3>
<?php if (empty($pending_qa)): ?>
<div style="text-align:center;padding:60px;color:#666;border:2px solid #ddd;background:#f9f9f9;border-radius:8px;margin-bottom:30px;">
  <h3 style="margin:0 0 10px 0;font-size:18px;color:#333;">NO PENDING REQUESTS</h3>
  <p style="margin:0;font-size:15px;">All requests have been reviewed. Check back later.</p>
</div>
<?php else: ?>
<table class="list-table" style="margin-bottom:35px;">
  <thead>
    <tr>
      <th>TR No.</th>
      <th>Satellite / Test Object</th>
      <th>Project</th>
      <th>Submitted By</th>
      <th>Submitted Date</th>
      <th>Action</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($pending_qa as $req): ?>
  <tr>
    <td><em style="color:#999;font-size:12px;">Pending TR</em></td>
    <td><strong><?php echo esc_html($req->satellite_name); ?></strong></td>
    <td><?php echo esc_html($req->project_program); ?></td>
    <td><?php echo esc_html($req->sub_name); ?></td>
    <td><?php echo date('d M Y, h:i A', strtotime($req->submission_date)); ?></td>
    <td>
      <a href="<?php echo esc_url(add_query_arg('qa_view', $req->id)); ?>" class="btn btn-view" style="background:#6f42c1;">
        Review Request
      </a>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<!-- REVIEW HISTORY TABLE -->
<h3>&#128203; Review History (Last 20)</h3>
<?php if (empty($reviewed)): ?>
<p style="color:#666;padding:20px 0;">No reviews submitted yet.</p>
<?php else: ?>
<table class="list-table">
  <thead>
    <tr>
      <th>TR No.</th>
      <th>Satellite / Test Object</th>
      <th>Project</th>
      <th>Submitted By</th>
      <th>Decision</th>
      <th>Review Date</th>
      <th>Remarks</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($reviewed as $req):
    $dec_badge = ($req->qa_decision === 'accept')
        ? '<span class="badge badge-approved" style="font-size:11px;padding:4px 10px;">✓ ACCEPTED</span>'
        : '<span class="badge badge-qa-rejected" style="font-size:11px;padding:4px 10px;">✗ REJECTED</span>';
  ?>
  <tr>
    <td>
      <?php
        $tr_disp = (strpos($req->test_requisition_no,'PENDING-')===0 || strpos($req->test_requisition_no,'DRAFT-')===0)
            ? '<em style="color:#999;font-size:12px;">Not yet assigned</em>'
            : '<strong>'.esc_html($req->test_requisition_no).'</strong>';
        echo $tr_disp;
      ?>
    </td>
    <td><?php echo esc_html($req->satellite_name); ?></td>
    <td><?php echo esc_html($req->project_program); ?></td>
    <td><?php echo esc_html($req->sub_name); ?></td>
    <td><?php echo $dec_badge; ?></td>
    <td><?php echo !empty($req->qa_review_date) ? date('d M Y, h:i A', strtotime($req->qa_review_date)) : '—'; ?></td>
    <td style="max-width:200px;font-size:13px;color:#555;"><?php echo esc_html($req->qa_remarks ?: '—'); ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
</div>

<?php
    }
// =====================================================================
//  MANAGER VIEW
// =====================================================================
} elseif ($user_role === 'manager') {

    $mgr_action = $_GET['mgr_action'] ?? 'dashboard';
    $view_id    = intval($_GET['view_id'] ?? 0);
    $prog_id    = intval($_GET['prog_id'] ?? 0);

    $cnt_pending   = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='pending'");
    $cnt_approved  = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='approved'");
    $cnt_rejected  = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='rejected'");
    $cnt_completed = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='completed'");

    if ($view_id) {
        $req = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $view_id));
        if ($req): 
            // Get pipeline with conditional QA logic
            $qa_required = strtolower($req->qa_exists ?? 'no');
            $steps = lssc_get_extended_pipeline_steps($req, $qa_required);
        $bc='badge-pending';
        if($req->status==='pending_qa')      $bc='badge-pending-qa';
        if($req->status==='qa_rejected')     $bc='badge-qa-rejected';
        if($req->status==='approved')        $bc='badge-approved';
        if($req->status==='rejected')        $bc='badge-rejected';
        if($req->status==='recheck_required') $bc='badge-recheck-required';
        if($req->status==='completed')       $bc='badge-completed';
        if($req->status==='in_testing')      $bc='badge-approved';
        $is_readonly = !in_array($req->status, ['pending']);
        ?>
<div class="container">
<div class="role-indicator">MANAGER VIEW | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>
<div style="margin-bottom:25px;"><a href="<?php echo get_permalink(); ?>" class="btn btn-primary">← Back to Dashboard</a></div>
<div class="request-card">
  <div class="request-header">
    <div><h2 style="margin:0;">TR No: <?php echo esc_html($req->test_requisition_no); ?></h2><small style="color:#666;">Submitted: <?php echo date('d M Y, h:i A', strtotime($req->submission_date)); ?></small></div>
    <span class="badge <?php echo $bc; ?>"><?php echo strtoupper($req->status); ?></span>
  </div>

  <h3 style="margin-top:25px;">Live Progress Pipeline</h3>
  <?php lssc_pipeline($steps); ?>

  <h3>Indenter Request Details (Read-Only)</h3>
  <table>
    <tr><th>Satellite/Test Object</th><th>Test Type</th><th>Project/Program</th></tr>
    <tr><td><?php echo esc_html($req->satellite_name); ?></td><td><?php echo esc_html($req->test_type); ?></td><td><?php echo esc_html($req->project_program); ?></td></tr>
    <tr><th>Subsystem Engineer</th><td colspan="2"><?php echo esc_html($req->sub_name); ?> (<?php echo esc_html($req->sub_stno); ?>)</td></tr>
    <tr><th>Test Required on</th><td colspan="2"><?php echo !empty($req->test_required_on) ? date('d M Y', strtotime($req->test_required_on)) : '—'; ?></td></tr>
    <tr><th>Section / Division</th><td colspan="2"><?php echo esc_html(($req->sub_section ?: '—').' / '.($req->sub_division ?: '—')); ?></td></tr>
    <?php if($req->qa_exists === 'yes' && !empty($req->qa_name)): ?>
    <tr><th>QA / T&amp;E Engineer</th><td colspan="2"><?php echo esc_html($req->qa_name . ' (' . $req->qa_stno . ')'); ?> &mdash; <small><?php echo esc_html($req->qa_section); ?></small></td></tr>
    <?php endif; ?>
    <?php if(!empty($req->special_requirements)):?><tr><th>Special Requirements</th><td colspan="2" style="background:#fff3cd;"><?php echo nl2br(esc_html($req->special_requirements)); ?></td></tr><?php endif;?>
  </table>

  <h3 style="margin-top:25px;">Chamber Interface Requirements (Read-Only)</h3>
  <table style="width:100%;border-collapse:collapse;">
    <tr><th style="border:1px solid #000;padding:10px;width:5%">Sl.</th><th style="border:1px solid #000;padding:10px;width:38%">Description</th><th style="border:1px solid #000;padding:10px;width:18%">No. of Channels</th><th style="border:1px solid #000;padding:10px;">Comments</th></tr>
    <tr><td style="border:1px solid #000;padding:10px;">1</td><td style="border:1px solid #000;padding:10px;">Thermal Power Channels</td><td style="border:1px solid #000;padding:10px;"><?php echo intval($req->thermal_power); ?></td><td style="border:1px solid #000;padding:10px;"><?php echo esc_html($req->thermal_power_comments ?: '—'); ?></td></tr>
    <tr><td style="border:1px solid #000;padding:10px;">2</td><td style="border:1px solid #000;padding:10px;">Thermal Thermocouples</td><td style="border:1px solid #000;padding:10px;"><?php echo intval($req->thermal_thermocouples); ?></td><td style="border:1px solid #000;padding:10px;"><?php echo esc_html($req->thermal_thermocouples_comments ?: '—'); ?></td></tr>
    <tr><td style="border:1px solid #000;padding:10px;">3</td><td style="border:1px solid #000;padding:10px;">Ground Checkout DC Signal Lines</td><td style="border:1px solid #000;padding:10px;"><?php echo intval($req->ground_dc_signal); ?></td><td style="border:1px solid #000;padding:10px;"><?php echo esc_html($req->ground_dc_signal_comments ?: '—'); ?></td></tr>
    <tr><td style="border:1px solid #000;padding:10px;">4</td><td style="border:1px solid #000;padding:10px;">Ground Signal Power Lines</td><td style="border:1px solid #000;padding:10px;"><?php echo intval($req->ground_signal_power); ?></td><td style="border:1px solid #000;padding:10px;"><?php echo esc_html($req->ground_signal_power_comments ?: '—'); ?></td></tr>
    <tr><td style="border:1px solid #000;padding:10px;">5</td><td style="border:1px solid #000;padding:10px;">Ground Checkout RF Connectors<br><small style="color:#555;font-weight:normal;"><?php echo esc_html($req->rf_connector_type ?: '—'); ?></small></td><td style="border:1px solid #000;padding:10px;"><?php echo intval($req->rf_connector_channels); ?></td><td style="border:1px solid #000;padding:10px;"><?php echo esc_html($req->rf_connector_comments ?: '—'); ?></td></tr>
  </table>
  
  <?php if ($req->status === 'pending'): ?>
  <h3 style="margin-top:30px;">MANAGER DECISION & APPROVAL</h3>
  <form method="post" style="margin-top:0;" onsubmit="return lssc_manager_submit(event)">
    <?php wp_nonce_field('lssc_action','lssc_nonce'); ?>
    <input type="hidden" name="form_id" value="<?php echo $req->id; ?>">
    
    <!-- MANAGER COMMENTS AT TOP (MANDATORY FOR REJECT/RECHECK) -->
    <div style="background:#fff3cd;padding:15px;border:1px solid #ffc107;border-radius:4px;margin-bottom:20px;">
      <label style="font-weight:700;display:block;margin-bottom:8px;color:#856404;">
        Manager Decision Comments
        <span style="color:#dc3545;">(Mandatory for Reject/Recheck)</span>
      </label>
      <textarea 
        name="manager_final_comment" 
        id="mgr_final_comment"
        rows="4" 
        placeholder="Enter your comments. If Rejecting/Rechecking, explain the reason/required changes."
        style="width:100%;border:1px solid #000;padding:12px;font-family:Arial,sans-serif;"><?php echo esc_textarea($req->manager_final_comment ?? ''); ?></textarea>
      <small style="color:#666;display:block;margin-top:8px;">
        • <strong>Approve:</strong> Environmental requirements will be available for filling after approval.
        • <strong>Reject:</strong> Specify reasons. Request returns to history.
        • <strong>Recheck:</strong> Specify required changes. Request goes back to Indenter for revision.
      </small>
    </div>

    <!-- ACTION BUTTONS -->
    <div style="margin-top:25px;padding:15px;background:#f0f0f0;border-radius:4px;">
      <p style="margin:0 0 15px 0;font-weight:600;">Select Your Action:</p>
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <button type="submit" name="manager_action" value="approve" class="btn" style="background:#28a745;color:#fff;padding:12px 24px;font-weight:600;border:none;cursor:pointer;border-radius:4px;">✓ APPROVE</button>
        <button type="submit" name="manager_action" value="reject" class="btn" style="background:#dc3545;color:#fff;padding:12px 24px;font-weight:600;border:none;cursor:pointer;border-radius:4px;">✗ REJECT</button>
        <button type="submit" name="manager_action" value="recheck" class="btn" style="background:#ffc107;color:#000;padding:12px 24px;font-weight:600;border:none;cursor:pointer;border-radius:4px;">⟲ RECHECK</button>
      </div>
      <small style="display:block;margin-top:12px;color:#666;">
        • <strong>APPROVE:</strong> Proceed to fill Environmental Requirements after approval.
        • <strong>REJECT:</strong> Explain rejection in comments box above. Request will be archived.
        • <strong>RECHECK:</strong> Explain required changes in comments. Request returns to Indenter for revision.
      </small>
    </div>
  </form>
  
  <?php elseif ($req->status === 'approved'): ?>
  <!-- ENVIRONMENTAL REQUIREMENTS - EDITABLE AFTER APPROVAL -->
  <h3 style="margin-top:30px;">Test Environmental Requirements - Manager Review</h3>
  <p style="color:#28a745;background:#d4edda;padding:12px;border-radius:4px;margin-bottom:15px;">
    ✓ Request approved. Please fill in the Environmental Requirements below and submit.
  </p>
  <form method="post" style="margin-top:0;">
    <?php wp_nonce_field('lssc_action','lssc_nonce'); ?>
    <input type="hidden" name="form_id" value="<?php echo $req->id; ?>">
    <input type="hidden" name="save_env_requirements" value="1">
    
    <table style="width:100%;border-collapse:collapse;" id="env_requirements_table">
      <tr>
        <th style="border:1px solid #000;padding:12px;text-align:left;background:#f5f5f5;width:5%">Sl</th>
        <th style="border:1px solid #000;padding:12px;text-align:left;background:#f5f5f5;width:25%">Description of Environment</th>
        <th style="border:1px solid #000;padding:12px;text-align:left;background:#f5f5f5;width:35%">Specifications</th>
        <th style="border:1px solid #000;padding:12px;text-align:left;background:#f5f5f5;width:35%">Manager Comments</th>
      </tr>
      <!-- Row 1: Vacuum -->
      <tr>
        <td style="border:1px solid #000;padding:12px;">1</td>
        <td style="border:1px solid #000;padding:12px;">Vacuum</td>
        <td style="border:1px solid #000;padding:12px;">
          <input class="env-spec-input" type="text" name="env_vacuum" placeholder="1 x 10-3 mbar or better" style="width:100%;padding:8px;border:1px solid #ccc;" value="<?php echo esc_attr($req->env_vacuum ?? ''); ?>">
        </td>
        <td style="border:1px solid #000;padding:12px;">
          <textarea class="env-comment-input" name="env_vacuum_mgr_comment" placeholder="Your review comment" style="width:100%;padding:8px;border:1px solid #ccc;font-family:Arial;height:60px;resize:vertical;"><?php echo esc_textarea($req->env_vacuum_mgr_comment ?? ''); ?></textarea>
        </td>
      </tr>
      <!-- Row 2: Shroud Temperature -->
      <tr>
        <td style="border:1px solid #000;padding:12px;">2</td>
        <td style="border:1px solid #000;padding:12px;">Shroud Temperature</td>
        <td style="border:1px solid #000;padding:12px;">
          <input class="env-spec-input" type="text" name="env_shroud_temp" placeholder="Ambient (25 +/- 5 deg C)" style="width:100%;padding:8px;border:1px solid #ccc;" value="<?php echo esc_attr($req->env_shroud_temp ?? ''); ?>">
        </td>
        <td style="border:1px solid #000;padding:12px;">
          <textarea class="env-comment-input" name="env_shroud_temp_mgr_comment" placeholder="Your review comment" style="width:100%;padding:8px;border:1px solid #ccc;font-family:Arial;height:60px;resize:vertical;"><?php echo esc_textarea($req->env_shroud_temp_mgr_comment ?? ''); ?></textarea>
        </td>
      </tr>
      <!-- Row 3: Solar Beam Intensity -->
      <tr>
        <td style="border:1px solid #000;padding:12px;">3</td>
        <td style="border:1px solid #000;padding:12px;">Solar Beam Intensity</td>
        <td style="border:1px solid #000;padding:12px;">
          <input class="env-spec-input" type="text" name="env_solar_beam" placeholder="NA" style="width:100%;padding:8px;border:1px solid #ccc;" value="<?php echo esc_attr($req->env_solar_beam ?? ''); ?>">
        </td>
        <td style="border:1px solid #000;padding:12px;">
          <textarea class="env-comment-input" name="env_solar_beam_mgr_comment" placeholder="Your review comment" style="width:100%;padding:8px;border:1px solid #ccc;font-family:Arial;height:60px;resize:vertical;"><?php echo esc_textarea($req->env_solar_beam_mgr_comment ?? ''); ?></textarea>
        </td>
      </tr>
      <!-- Row 4: Eclipse Details -->
      <tr>
        <td style="border:1px solid #000;padding:12px;">4</td>
        <td style="border:1px solid #000;padding:12px;">Eclipse Details</td>
        <td style="border:1px solid #000;padding:12px;">
          <input class="env-spec-input" type="text" name="env_eclipse" placeholder="NA" style="width:100%;padding:8px;border:1px solid #ccc;" value="<?php echo esc_attr($req->env_eclipse ?? ''); ?>">
        </td>
        <td style="border:1px solid #000;padding:12px;">
          <textarea class="env-comment-input" name="env_eclipse_mgr_comment" placeholder="Your review comment" style="width:100%;padding:8px;border:1px solid #ccc;font-family:Arial;height:60px;resize:vertical;"><?php echo esc_textarea($req->env_eclipse_mgr_comment ?? ''); ?></textarea>
        </td>
      </tr>
      <!-- Row 5: Motion Simulator -->
      <tr>
        <td style="border:1px solid #000;padding:12px;">5</td>
        <td style="border:1px solid #000;padding:12px;">Motion Simulator Axis / Tilt</td>
        <td style="border:1px solid #000;padding:12px;">
          <input class="env-spec-input" type="text" name="env_motion_tilt" placeholder="NA" style="width:100%;padding:8px;border:1px solid #ccc;" value="<?php echo esc_attr($req->env_motion_tilt ?? ''); ?>">
        </td>
        <td style="border:1px solid #000;padding:12px;">
          <textarea class="env-comment-input" name="env_motion_tilt_mgr_comment" placeholder="Your review comment" style="width:100%;padding:8px;border:1px solid #ccc;font-family:Arial;height:60px;resize:vertical;"><?php echo esc_textarea($req->env_motion_tilt_mgr_comment ?? ''); ?></textarea>
        </td>
      </tr>
      <!-- Row 6: Motion Spinner -->
      <tr>
        <td style="border:1px solid #000;padding:12px;">6</td>
        <td style="border:1px solid #000;padding:12px;">Motion Simulator Axis / Spin</td>
        <td style="border:1px solid #000;padding:12px;">
          <input class="env-spec-input" type="text" name="env_motion_spin" placeholder="NA" style="width:100%;padding:8px;border:1px solid #ccc;" value="<?php echo esc_attr($req->env_motion_spin ?? ''); ?>">
        </td>
        <td style="border:1px solid #000;padding:12px;">
          <textarea class="env-comment-input" name="env_motion_spin_mgr_comment" placeholder="Your review comment" style="width:100%;padding:8px;border:1px solid #ccc;font-family:Arial;height:60px;resize:vertical;"><?php echo esc_textarea($req->env_motion_spin_mgr_comment ?? ''); ?></textarea>
        </td>
      </tr>
      <!-- Row 7: Motion Speed -->
      <tr>
        <td style="border:1px solid #000;padding:12px;">7</td>
        <td style="border:1px solid #000;padding:12px;">Motion Simulator Speed / RPM</td>
        <td style="border:1px solid #000;padding:12px;">
          <input class="env-spec-input" type="text" name="env_motion_speed" placeholder="NA" style="width:100%;padding:8px;border:1px solid #ccc;" value="<?php echo esc_attr($req->env_motion_speed ?? ''); ?>">
        </td>
        <td style="border:1px solid #000;padding:12px;">
          <textarea class="env-comment-input" name="env_motion_speed_mgr_comment" placeholder="Your review comment" style="width:100%;padding:8px;border:1px solid #ccc;font-family:Arial;height:60px;resize:vertical;"><?php echo esc_textarea($req->env_motion_speed_mgr_comment ?? ''); ?></textarea>
        </td>
      </tr>
      <!-- Row 8: Mechanical Interface -->
      <tr>
        <td style="border:1px solid #000;padding:12px;">8</td>
        <td style="border:1px solid #000;padding:12px;">Mechanical Interface Requirement</td>
        <td style="border:1px solid #000;padding:12px;">
          <input class="env-spec-input" type="text" name="env_mechanical" placeholder="Interface adaptor to match dia" style="width:100%;padding:8px;border:1px solid #ccc;" value="<?php echo esc_attr($req->env_mechanical ?? ''); ?>">
        </td>
        <td style="border:1px solid #000;padding:12px;">
          <textarea class="env-comment-input" name="env_mechanical_mgr_comment" placeholder="Your review comment" style="width:100%;padding:8px;border:1px solid #ccc;font-family:Arial;height:60px;resize:vertical;"><?php echo esc_textarea($req->env_mechanical_mgr_comment ?? ''); ?></textarea>
        </td>
      </tr>
      <!-- Row 9: Special Requirements -->
      <tr>
        <td style="border:1px solid #000;padding:12px;">9</td>
        <td style="border:1px solid #000;padding:12px;">Special Requirements (if any)</td>
        <td style="border:1px solid #000;padding:12px;">
          <input class="env-spec-input" type="text" name="env_special_req" placeholder="Nil" style="width:100%;padding:8px;border:1px solid #ccc;" value="<?php echo esc_attr($req->env_special_req ?? ''); ?>">
        </td>
        <td style="border:1px solid #000;padding:12px;">
          <textarea class="env-comment-input" name="env_special_req_mgr_comment" placeholder="Your review comment" style="width:100%;padding:8px;border:1px solid #ccc;font-family:Arial;height:60px;resize:vertical;"><?php echo esc_textarea($req->env_special_req_mgr_comment ?? ''); ?></textarea>
        </td>
      </tr>
      <!-- Row 10: Key Characteristics -->
      <tr>
        <td style="border:1px solid #000;padding:12px;">10</td>
        <td style="border:1px solid #000;padding:12px;">Key Characteristics (if any)</td>
        <td style="border:1px solid #000;padding:12px;">
          <input class="env-spec-input" type="text" name="env_key_char" placeholder="Nil" style="width:100%;padding:8px;border:1px solid #ccc;" value="<?php echo esc_attr($req->env_key_char ?? ''); ?>">
        </td>
        <td style="border:1px solid #000;padding:12px;">
          <textarea class="env-comment-input" name="env_key_char_mgr_comment" placeholder="Your review comment" style="width:100%;padding:8px;border:1px solid #ccc;font-family:Arial;height:60px;resize:vertical;"><?php echo esc_textarea($req->env_key_char_mgr_comment ?? ''); ?></textarea>
        </td>
      </tr>
    </table>

    <!-- SUBMIT BUTTON -->
    <div style="margin-top:25px;padding:15px;background:#f0f0f0;border-radius:4px;">
      <button type="submit" class="btn" style="background:#007bff;color:#fff;padding:12px 24px;font-weight:600;border:none;cursor:pointer;border-radius:4px;">
        💾 SAVE ENVIRONMENTAL REQUIREMENTS
      </button>
      <small style="display:block;margin-top:12px;color:#666;">
        Fill in all environmental requirement specifications and comments, then submit to finalize.
      </small>
    </div>
  </form>
  
  <?php else: ?>
  <!-- READ-ONLY VIEW FOR NON-PENDING REQUESTS -->
  <h3 style="margin-top:30px;">Environmental Requirements (Read-Only - Locked)</h3>
  <p style="color:#d39e00;background:#fff3cd;padding:12px;border-radius:4px;margin-bottom:15px;border:1px solid #ffc107;">
    ✓ Environmental requirements submitted and locked. No further edits allowed.
  </p>
  <table style="width:100%;border-collapse:collapse;">
    <tr>
      <th style="border:1px solid #000;padding:12px;text-align:left;background:#f5f5f5;">Sl</th>
      <th style="border:1px solid #000;padding:12px;text-align:left;background:#f5f5f5;">Description</th>
      <th style="border:1px solid #000;padding:12px;text-align:left;background:#f5f5f5;">Specifications</th>
      <th style="border:1px solid #000;padding:12px;text-align:left;background:#f5f5f5;">Manager Comments</th>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:12px;">1</td>
      <td style="border:1px solid #000;padding:12px;">Vacuum</td>
      <td style="border:1px solid #000;padding:12px;"><?php echo esc_html($req->env_vacuum ?: '—'); ?></td>
      <td style="border:1px solid #000;padding:12px;"><?php echo nl2br(esc_html($req->env_vacuum_mgr_comment ?: '—')); ?></td>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:12px;">2</td>
      <td style="border:1px solid #000;padding:12px;">Shroud Temperature</td>
      <td style="border:1px solid #000;padding:12px;"><?php echo esc_html($req->env_shroud_temp ?: '—'); ?></td>
      <td style="border:1px solid #000;padding:12px;"><?php echo nl2br(esc_html($req->env_shroud_temp_mgr_comment ?: '—')); ?></td>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:12px;">3</td>
      <td style="border:1px solid #000;padding:12px;">Solar Beam Intensity</td>
      <td style="border:1px solid #000;padding:12px;"><?php echo esc_html($req->env_solar_beam ?: '—'); ?></td>
      <td style="border:1px solid #000;padding:12px;"><?php echo nl2br(esc_html($req->env_solar_beam_mgr_comment ?: '—')); ?></td>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:12px;">4</td>
      <td style="border:1px solid #000;padding:12px;">Eclipse Details</td>
      <td style="border:1px solid #000;padding:12px;"><?php echo esc_html($req->env_eclipse ?: '—'); ?></td>
      <td style="border:1px solid #000;padding:12px;"><?php echo nl2br(esc_html($req->env_eclipse_mgr_comment ?: '—')); ?></td>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:12px;">5</td>
      <td style="border:1px solid #000;padding:12px;">Motion Simulator / Tilt</td>
      <td style="border:1px solid #000;padding:12px;"><?php echo esc_html($req->env_motion_tilt ?: '—'); ?></td>
      <td style="border:1px solid #000;padding:12px;"><?php echo nl2br(esc_html($req->env_motion_tilt_mgr_comment ?: '—')); ?></td>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:12px;">6</td>
      <td style="border:1px solid #000;padding:12px;">Motion Simulator / Spin</td>
      <td style="border:1px solid #000;padding:12px;"><?php echo esc_html($req->env_motion_spin ?: '—'); ?></td>
      <td style="border:1px solid #000;padding:12px;"><?php echo nl2br(esc_html($req->env_motion_spin_mgr_comment ?: '—')); ?></td>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:12px;">7</td>
      <td style="border:1px solid #000;padding:12px;">Motion Simulator / Speed</td>
      <td style="border:1px solid #000;padding:12px;"><?php echo esc_html($req->env_motion_speed ?: '—'); ?></td>
      <td style="border:1px solid #000;padding:12px;"><?php echo nl2br(esc_html($req->env_motion_speed_mgr_comment ?: '—')); ?></td>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:12px;">8</td>
      <td style="border:1px solid #000;padding:12px;">Mechanical Interface</td>
      <td style="border:1px solid #000;padding:12px;"><?php echo esc_html($req->env_mechanical ?: '—'); ?></td>
      <td style="border:1px solid #000;padding:12px;"><?php echo nl2br(esc_html($req->env_mechanical_mgr_comment ?: '—')); ?></td>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:12px;">9</td>
      <td style="border:1px solid #000;padding:12px;">Special Requirements</td>
      <td style="border:1px solid #000;padding:12px;"><?php echo esc_html($req->env_special_req ?: '—'); ?></td>
      <td style="border:1px solid #000;padding:12px;"><?php echo nl2br(esc_html($req->env_special_req_mgr_comment ?: '—')); ?></td>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:12px;">10</td>
      <td style="border:1px solid #000;padding:12px;">Key Characteristics</td>
      <td style="border:1px solid #000;padding:12px;"><?php echo esc_html($req->env_key_char ?: '—'); ?></td>
      <td style="border:1px solid #000;padding:12px;"><?php echo nl2br(esc_html($req->env_key_char_mgr_comment ?: '—')); ?></td>
    </tr>
  </table>

  <h3 style="margin-top:25px;">Manager Review Decision</h3>
  <div style="border:2px solid #ddd;padding:20px;background:#f9f9f9;border-radius:4px;">
    <p><strong style="font-size:16px;">Action Taken:</strong> <span style="font-size:16px;color:<?php echo in_array($req->manager_action, ['approve','approved'])?'#28a745':(in_array($req->manager_action, ['reject','rejected'])?'#dc3545':'#ffc107'); ?>;"><?php echo strtoupper($req->manager_action ?: $req->status); ?></span></p>
    <p><strong>Decision Date:</strong> <?php echo !empty($req->approval_date) ? date('d M Y, h:i A', strtotime($req->approval_date)) : 'N/A'; ?></p>
    <p><strong>Decided By:</strong> <?php echo esc_html($emp->name ?: 'Unable to determine'); ?></p>
    <?php if (!empty($req->manager_final_comment)): ?>
    <p><strong>Decision Comment:</strong></p>
    <div style="background:#fff;border:1px solid #ddd;padding:12px;border-radius:3px;"><p><?php echo nl2br(esc_html($req->manager_final_comment)); ?></p></div>
    <?php else: ?>
    <p><em style="color:#999;">No additional comments provided</em></p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>
</div>
<?php
        else: echo "<p style='text-align:center;color:#dc3545;padding:50px;'>Request not found or already processed.</p>"; endif;

    } elseif ($mgr_action === 'create_new') {
        // Check for existing manager draft to resume
        $existing_draft = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id=%d AND status='draft_indenter' ORDER BY indenter_draft_saved_at DESC LIMIT 1",
            $user->ID
        )); ?>
<div class="form-container">
<div class="role-indicator">MANAGER VIEW | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>
<div style="margin-bottom:25px;"><a href="<?php echo get_permalink(); ?>" class="btn btn-primary">← Back to Dashboard</a></div>
<h1>New Request Submission</h1>
<?php 
// Display validation errors if any
$_mgr_errs = get_transient('lssc_errors_'.$user->ID);
if (!empty($_mgr_errs)) {
    delete_transient('lssc_errors_'.$user->ID);
    echo "<div style='background:#f8d7da;color:#721c24;border:2px solid #dc3545;padding:16px 20px;margin-bottom:20px;border-radius:4px;'>";
    echo "<strong>⚠ Submission Failed - Please Fix These Errors:</strong><ul style='margin:10px 0 0 20px;padding:0;'>";
    foreach ($_mgr_errs as $e) echo "<li>".esc_html($e)."</li>";
    echo "</ul></div>";
}
?>
<?php mgr_stat_cards($cnt_pending,$cnt_approved,$cnt_rejected,$cnt_completed); ?>
<?php mgr_tabs('create_new', $cnt_pending); ?>
<?php lssc_request_form($emp, $existing_draft, admin_url('admin-ajax.php')); ?>
</div>
<?php

    } elseif ($mgr_action === 'my_requests') {
        $my = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE user_id=%d ORDER BY submission_date DESC", $user->ID)); ?>
<div class="container">
<div class="role-indicator">MANAGER VIEW | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>
<?php mgr_stat_cards($cnt_pending,$cnt_approved,$cnt_rejected,$cnt_completed); ?>
<?php mgr_tabs('my_requests', $cnt_pending); ?>
<h1>My Submitted Requests</h1>
<?php if(empty($my)): ?><div style="text-align:center;padding:80px;color:#666;border:2px solid #ddd;background:#f9f9f9;border-radius:8px;"><h3 style="margin:0 0 10px 0;font-size:18px;color:#333;">NO REQUESTS SUBMITTED</h3><p style="margin:0;font-size:15px;">Click "+ NEW REQUEST" to create one.</p></div>
<?php else: ?>
<table class="list-table">
  <thead><tr><th>TR No.</th><th>Satellite/Test Object</th><th>Project</th><th>Submitted Date</th><th>Status</th><th>Action</th></tr></thead>
  <tbody>
  <?php foreach($my as $req): $bc='badge-pending';if($req->status==='approved')$bc='badge-approved';if($req->status==='rejected')$bc='badge-rejected';if($req->status==='recheck_required')$bc='badge-recheck-required';if($req->status==='completed')$bc='badge-completed'; ?>
  <tr><td><strong><?php echo esc_html($req->test_requisition_no); ?></strong></td><td><?php echo esc_html($req->satellite_name); ?></td><td><?php echo esc_html($req->project_program); ?></td><td><?php echo date('d M Y, h:i A', strtotime($req->submission_date)); ?></td><td><span class="badge <?php echo $bc; ?>"><?php echo strtoupper($req->status); ?></span></td><td><a href="<?php echo add_query_arg('view_id',$req->id); ?>" class="btn btn-view">View Details</a></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
</div>
<?php

    } elseif ($mgr_action === 'progress') {
        $in_prog   = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE status=%s ORDER BY approval_date DESC", 'approved'));
        $completed = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE status=%s ORDER BY completion_date DESC LIMIT 10", 'completed')); ?>
<div class="container">
<div class="role-indicator">MANAGER VIEW | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>
<?php mgr_stat_cards($cnt_pending,$cnt_approved,$cnt_rejected,$cnt_completed); ?>
<?php mgr_tabs('progress', $cnt_pending); ?>
<h1>Track Post-Approval Progress</h1>

<?php if(empty($in_prog)): ?>
<div style="text-align:center;padding:40px;color:#666;border:2px solid #ddd;margin-bottom:30px;"><h3 style="margin:0;">No requests currently in testing</h3></div>
<?php else: ?>
<h3 style="margin-top:0;">Currently In Testing (<?php echo count($in_prog); ?>)</h3>
<?php foreach($in_prog as $req):
    $etf_step='Awaiting LSSC';
    if(!empty($req->occ_user))   $etf_step='🔵 Chamber Occupied';
    if(!empty($req->start_user)) $etf_step='🟡 Test Running';
    if(!empty($req->end_user))   $etf_step='🟠 Awaiting Chamber Vacate';
?>
<div style="border:1px solid #ddd;padding:18px 22px;margin-bottom:12px;background:#fff;display:flex;justify-content:space-between;align-items:center;border-radius:4px;">
  <div>
    <div style="font-weight:600;font-size:15px;"><?php echo esc_html($req->test_requisition_no.' — '.$req->satellite_name); ?></div>
    <div style="font-size:13px;color:#555;"><?php echo esc_html($req->project_program); ?> | Approved: <?php echo !empty($req->approval_date)?date('d M Y',strtotime($req->approval_date)):'—'; ?> | Status: <strong><?php echo $etf_step; ?></strong></div>
  </div>
  <a href="<?php echo esc_url(get_permalink().'?mgr_action=progress&prog_id='.$req->id); ?>" class="btn btn-info">Details →</a>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php if(!empty($completed)): ?>
<h3>Recently Completed (Last 10)</h3>
<table class="list-table" style="margin-top:0;">
  <thead><tr><th>TR No.</th><th>Satellite</th><th>Project</th><th>Completed</th></tr></thead>
  <tbody>
  <?php foreach($completed as $req):?>
  <tr><td><strong><?php echo esc_html($req->test_requisition_no); ?></strong></td><td><?php echo esc_html($req->satellite_name); ?></td><td><?php echo esc_html($req->project_program); ?></td><td><?php echo !empty($req->completion_date)?date('d M Y',strtotime($req->completion_date)):'—'; ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
</div>
<?php

    } elseif ($mgr_action === 'pending') {
        $pending = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE status=%s ORDER BY submission_date DESC", 'pending')); ?>
<div class="container">
<div class="role-indicator">MANAGER VIEW | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>
<?php mgr_stat_cards($cnt_pending,$cnt_approved,$cnt_rejected,$cnt_completed); ?>
<?php mgr_tabs('pending', $cnt_pending); ?>
<h1>Pending Approvals</h1>
<?php if(empty($pending)): ?><div style="text-align:center;padding:60px;color:#666;border:2px solid #ddd;"><h3 style="margin:0;">No pending requests</h3></div>
<?php else: ?>
<table class="list-table">
  <thead><tr><th>TR No.</th><th>Satellite/Test Object</th><th>Project</th><th>Submitted By</th><th>Submitted Date</th><th>Action</th></tr></thead>
  <tbody>
  <?php foreach($pending as $req):?>
  <?php 
    $tr_pend = (strpos($req->test_requisition_no,'PENDING-')===0||strpos($req->test_requisition_no,'DRAFT-')===0)
        ? '<em style="color:#999;font-size:12px;">No TR yet</em>'
        : '<strong>'.esc_html($req->test_requisition_no).'</strong>';
  ?>
  <tr><td><?php echo $tr_pend; ?></td><td><?php echo esc_html($req->satellite_name); ?></td><td><?php echo esc_html($req->project_program); ?></td><td><?php echo esc_html($req->sub_name); ?></td><td><?php echo date('d M Y, h:i A', strtotime($req->submission_date)); ?></td><td><a href="<?php echo esc_url(add_query_arg('view_id',$req->id)); ?>" class="btn btn-view">View & Approve</a></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
</div>
<?php

    } elseif ($mgr_action === 'in_testing') {
        $rows = $wpdb->get_results("SELECT * FROM {$table} WHERE status='approved' ORDER BY approval_date DESC"); ?>
<div class="container">
<div class="role-indicator">MANAGER VIEW | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>
<?php mgr_stat_cards($cnt_pending,$cnt_approved,$cnt_rejected,$cnt_completed); ?>
<?php mgr_tabs('in_testing', $cnt_pending); ?>
<h1>In Testing (<?php echo count($rows); ?>)</h1>
<?php if(empty($rows)): ?>
<div style="text-align:center;padding:60px;color:#666;border:2px solid #ddd;border-radius:6px;"><h3 style="margin:0;">No requests currently in testing</h3></div>
<?php else: ?>
<table class="list-table">
  <thead><tr><th>TR No.</th><th>Satellite / Test Object</th><th>Project</th><th>Submitted By</th><th>Approved On</th><th>Test Required On</th><th>Action</th></tr></thead>
  <tbody>
  <?php foreach($rows as $req): ?>
  <tr>
    <td><strong><?php echo esc_html($req->test_requisition_no); ?></strong></td>
    <td><?php echo esc_html($req->satellite_name); ?></td>
    <td><?php echo esc_html($req->project_program); ?></td>
    <td><?php echo esc_html($req->sub_name); ?></td>
    <td><?php echo !empty($req->approval_date) ? date('d M Y', strtotime($req->approval_date)) : '—'; ?></td>
    <td><?php echo !empty($req->test_required_on) ? date('d M Y', strtotime($req->test_required_on)) : '—'; ?></td>
    <td><a href="?view_id=<?php echo $req->id; ?>" class="btn-view">VIEW DETAILS</a></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
</div>
<?php

    } elseif ($mgr_action === 'rejected_list') {
        $rows = $wpdb->get_results("SELECT * FROM {$table} WHERE status='rejected' ORDER BY approval_date DESC"); ?>
<div class="container">
<div class="role-indicator">MANAGER VIEW | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>
<?php mgr_stat_cards($cnt_pending,$cnt_approved,$cnt_rejected,$cnt_completed); ?>
<?php mgr_tabs('rejected_list', $cnt_pending); ?>
<h1>Rejected Requests (<?php echo count($rows); ?>)</h1>
<?php if(empty($rows)): ?>
<div style="text-align:center;padding:60px;color:#666;border:2px solid #ddd;border-radius:6px;"><h3 style="margin:0;">No rejected requests</h3></div>
<?php else: ?>
<table class="list-table">
  <thead><tr><th>TR No.</th><th>Satellite / Test Object</th><th>Project</th><th>Submitted By</th><th>Rejected On</th><th>Manager Comments</th><th>Action</th></tr></thead>
  <tbody>
  <?php foreach($rows as $req): ?>
  <tr>
    <td><strong><?php echo esc_html($req->test_requisition_no); ?></strong></td>
    <td><?php echo esc_html($req->satellite_name); ?></td>
    <td><?php echo esc_html($req->project_program); ?></td>
    <td><?php echo esc_html($req->sub_name); ?></td>
    <td><?php echo !empty($req->approval_date) ? date('d M Y', strtotime($req->approval_date)) : '—'; ?></td>
    <td style="max-width:220px;font-size:13px;color:#555;"><?php echo esc_html($req->manager_comment ?: '—'); ?></td>
    <td><a href="?view_id=<?php echo $req->id; ?>" class="btn-view">VIEW DETAILS</a></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
</div>
<?php

    } elseif ($mgr_action === 'completed_list') {
        $rows = $wpdb->get_results("SELECT * FROM {$table} WHERE status='completed' ORDER BY completion_date DESC"); ?>
<div class="container">
<div class="role-indicator">MANAGER VIEW | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>
<?php mgr_stat_cards($cnt_pending,$cnt_approved,$cnt_rejected,$cnt_completed); ?>
<?php mgr_tabs('completed_list', $cnt_pending); ?>
<h1>Completed Tests (<?php echo count($rows); ?>)</h1>
<?php if(empty($rows)): ?>
<div style="text-align:center;padding:60px;color:#666;border:2px solid #ddd;border-radius:6px;"><h3 style="margin:0;">No completed tests yet</h3></div>
<?php else: ?>
<table class="list-table">
  <thead><tr><th>TR No.</th><th>Satellite / Test Object</th><th>Project</th><th>Submitted By</th><th>Approved On</th><th>Completed On</th><th>Action</th></tr></thead>
  <tbody>
  <?php foreach($rows as $req): ?>
  <tr>
    <td><strong><?php echo esc_html($req->test_requisition_no); ?></strong></td>
    <td><?php echo esc_html($req->satellite_name); ?></td>
    <td><?php echo esc_html($req->project_program); ?></td>
    <td><?php echo esc_html($req->sub_name); ?></td>
    <td><?php echo !empty($req->approval_date) ? date('d M Y', strtotime($req->approval_date)) : '—'; ?></td>
    <td><?php echo !empty($req->completion_date) ? date('d M Y', strtotime($req->completion_date)) : '—'; ?></td>
    <td><a href="?view_id=<?php echo $req->id; ?>" class="btn-view">VIEW DETAILS</a></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
</div>
<?php

    } else {
        $recent_all  = $wpdb->get_results("SELECT * FROM {$table} ORDER BY submission_date DESC LIMIT 6"); ?>
<div class="container">
<div class="role-indicator">MANAGER VIEW | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>
<?php $lssc_msg = sanitize_text_field($_GET['lssc_msg'] ?? '');
if ($lssc_msg === 'approved'): ?>
<div style="background:#d4edda;color:#155724;border:1px solid #c3e6cb;padding:14px 20px;margin-bottom:20px;border-radius:4px;">&#10003; <strong>Request approved successfully.</strong> Test Requisition Number has been assigned.</div>
<?php elseif ($lssc_msg === 'rejected'): ?>
<div style="background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;padding:14px 20px;margin-bottom:20px;border-radius:4px;">&#10003; <strong>Request rejected.</strong></div>
<?php elseif ($lssc_msg === 'env_req_saved'): ?>
<div style="background:#d4edda;color:#155724;border:1px solid #c3e6cb;padding:14px 20px;margin-bottom:20px;border-radius:4px;">&#10003; <strong>Environmental Requirements saved successfully.</strong></div>
<?php endif; ?>
<?php mgr_stat_cards($cnt_pending,$cnt_approved,$cnt_rejected,$cnt_completed); ?>
<?php mgr_tabs('dashboard', $cnt_pending); ?>

<div style="margin-top:10px;">
  <h3 style="margin-top:0;">Recent Submissions</h3>
  <?php if(empty($recent_all)): ?><p style="color:#666;">No submissions yet.</p>
  <?php else: ?>
  <table class="list-table" style="margin-top:0;">
    <thead><tr><th>TR No.</th><th>Satellite/Test Object</th><th>Project</th><th>Submitted By</th><th>Status</th><th>Date</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach($recent_all as $req): $bc='badge-pending';if($req->status==='approved')$bc='badge-approved';if($req->status==='rejected')$bc='badge-rejected';if($req->status==='completed')$bc='badge-completed'; ?>
    <tr><td><strong><?php echo esc_html($req->test_requisition_no); ?></strong></td><td><?php echo esc_html($req->satellite_name); ?></td><td><?php echo esc_html($req->project_program); ?></td><td><?php echo esc_html($req->sub_name ?: '—'); ?></td><td><span class="badge <?php echo $bc; ?>" style="padding:4px 10px;font-size:11px;"><?php echo strtoupper($req->status); ?></span></td><td style="font-size:12px;"><?php echo date('d M Y', strtotime($req->submission_date)); ?></td><td><a href="?view_id=<?php echo $req->id; ?>" class="btn-view">VIEW DETAILS</a></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <?php if($cnt_pending > 0): ?>
  <div style="margin-top:30px;padding:20px 25px;background:#fff3cd;border:2px solid #ffc107;display:flex;justify-content:space-between;align-items:center;border-radius:4px;">
    <div><strong style="color:#856404;font-size:15px;">⚠ <?php echo $cnt_pending; ?> Request<?php echo $cnt_pending>1?'s':''; ?> Awaiting Your Approval</strong></div>
    <a href="<?php echo esc_url(add_query_arg('mgr_action','pending')); ?>" class="btn btn-approve" style="padding:10px 22px;font-size:13px;">Review Now →</a>
  </div>
  <?php endif; ?>
</div>
<?php
    }

// =====================================================================
//  LSSC STAFF VIEW - 4-STAGE HIERARCHICAL LAYOUT
// =====================================================================
// Stage 1: Live Progress Pipeline
// Stage 2: Indenter Requirements (Read-Only)
// Stage 3: Manager Authorization & Test Conditions (Read-Only)  
// Stage 4: LSSC Staff Final Test Execution (Editable)
// =====================================================================
} elseif ($user_role === 'lssc') {
    $complete_id = intval($_GET['complete_id'] ?? 0);

    if ($complete_id) {
        $fd = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d AND status IN ('approved', 'Draft', 'Completed', 'completed')", $complete_id));
        if ($fd):
?>
<div class="form-container lssc-hierarchical-layout">
<div class="role-indicator">LSSC STAFF VIEW | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>

<!-- HEADER SECTION -->
<?php 
  $status_badge_class = 'status-badge-draft';
  $status_badge_label = 'Draft';
  if ($fd->status === 'Completed' || $fd->status === 'completed') {
    $status_badge_class = 'status-badge-completed';
    $status_badge_label = 'Completed';
  }
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:25px;margin-top:20px;">
  <h2 style="margin:0;font-size:22px;font-weight:600;">Test Requisition <?php echo esc_html($fd->test_requisition_no); ?></h2>
  <div class="<?php echo $status_badge_class; ?>">Status: <?php echo esc_html($status_badge_label); ?></div>
</div>

<!-- BACK LINK & NOTIFICATIONS -->
<div style="margin-bottom:20px;"><a href="<?php echo get_permalink(); ?>" class="btn btn-primary">← Back to List</a></div>

<?php if($fd->status === 'completed' || $fd->status === 'Completed'): ?>
<div style="background:#d4edda;border:2px solid #c3e6cb;padding:16px 20px;margin:15px 0;border-radius:4px;color:#155724;border-radius:6px;">
  <strong style="font-size:15px;">✓ Form Completed & Locked</strong><br>
  <span style="font-size:13px;">This requisition has been successfully completed. All sections are now read-only.</span>
</div>
<?php endif; ?>

<?php if(!empty($fd->draft_saved_at)): ?>
<div class="draft-notice" style="background:#cfe9f3;border:1px solid #86c6df;padding:12px 16px;margin:12px 0;border-radius:6px;color:#0c5460;font-size:13px;">
  <strong>💾 Draft Saved:</strong> Last saved by <strong><?php echo esc_html($fd->draft_saved_by??'Unknown'); ?></strong> on <strong><?php echo date('d M Y, H:i', strtotime($fd->draft_saved_at)); ?></strong>
</div>
<?php endif; ?>

<?php 
// Display validation errors if any
$_lssc_errs = get_transient('lssc_errors_'.$user->ID);
if (!empty($_lssc_errs)) {
    delete_transient('lssc_errors_'.$user->ID);
    echo "<div style='background:#f8d7da;color:#721c24;border:2px solid #dc3545;padding:16px 20px;margin-bottom:20px;border-radius:6px;'>";
    echo "<strong>⚠ Validation Errors:</strong><ul style='margin:10px 0 0 20px;padding:0;'>";
    foreach ($_lssc_errs as $e) echo "<li style='margin:4px 0;'>".esc_html($e)."</li>";
    echo "</ul></div>";
}
?>

<!-- =====================================================================
     STAGE 1: LIVE PROGRESS PIPELINE
     ===================================================================== -->
<?php 
if (!empty($fd->id)):
  $req_full = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $fd->id));
  if ($req_full):
    $qa_required = strtolower($req_full->qa_exists ?? 'no');
    $steps = lssc_get_extended_pipeline_steps($req_full, $qa_required);
?>
<div class="section-stage stage-pipeline">
  <h3 class="section-title stage-title">📊 Live Progress Pipeline</h3>
  <div class="stage-content">
    <?php lssc_pipeline($steps); ?>
  </div>
</div>
<hr class="section-divider">
<?php 
  endif;
endif;
?>

<!-- =====================================================================
     STAGE 2: INDENTER REQUIREMENTS (READ-ONLY)
     ===================================================================== -->
<?php 
if (!empty($fd->id)):
  $req_full = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $fd->id));
  if ($req_full):
?>
<div class="section-stage stage-indenter">
  <h3 class="section-title stage-title">📋 INDENTER REQUIREMENTS (READ ONLY)</h3>
  
  <div class="stage-content">
    <!-- Subsection: Request Details -->
    <div class="subsection-card">
      <h4 class="subsection-header">Request Details</h4>
      <table class="readonly-table">
        <tr>
          <th>Satellite/Test Object</th>
          <td><?php echo esc_html($req_full->satellite_name); ?></td>
        </tr>
        <tr>
          <th>Project/Program</th>
          <td><?php echo esc_html($req_full->project_program); ?></td>
        </tr>
        <tr>
          <th>Type of Test</th>
          <td><?php echo esc_html($req_full->test_type); ?></td>
        </tr>
        <tr>
          <th>Test Required By</th>
          <td><?php echo !empty($req_full->test_required_on) ? date('d M Y', strtotime($req_full->test_required_on)) : '—'; ?></td>
        </tr>
        <tr>
          <th>Subsystem Engineer</th>
          <td><?php echo esc_html($req_full->sub_name . ' (' . $req_full->sub_stno . ')'); ?></td>
        </tr>
        <tr>
          <th style="vertical-align:top;">Special Requirements</th>
          <td><?php echo !empty($req_full->special_requirements) ? nl2br(esc_html($req_full->special_requirements)) : '<em>—</em>'; ?></td>
        </tr>
      </table>
    </div>

    <!-- Subsection: Chamber Interface Requirements -->
    <div class="subsection-card" style="margin-top:20px;">
      <h4 class="subsection-header">Chamber Interface Requirements</h4>
      <table class="readonly-table">
        <tr>
          <th>Thermal Power Required</th>
          <td><?php echo intval($req_full->thermal_power) . ' (Units)'; ?></td>
        </tr>
        <tr>
          <th>Thermal Thermocouples</th>
          <td><?php echo intval($req_full->thermal_thermocouples) . ' (Channels)'; ?></td>
        </tr>
        <tr>
          <th>Ground DC Signal</th>
          <td><?php echo intval($req_full->ground_dc_signal) . ' (Channels)'; ?></td>
        </tr>
        <tr>
          <th>Ground Signal Power</th>
          <td><?php echo intval($req_full->ground_signal_power) . ' (Units)'; ?></td>
        </tr>
        <tr>
          <th>RF Connector Type</th>
          <td><?php echo !empty($req_full->rf_connector_type) ? esc_html($req_full->rf_connector_type) : '—'; ?></td>
        </tr>
        <tr>
          <th>RF Channels</th>
          <td><?php echo intval($req_full->rf_connector_channels) . ' (Channels)'; ?></td>
        </tr>
      </table>
    </div>

  </div>
</div>
<hr class="section-divider">

<!-- =====================================================================
     STAGE 3: MANAGER AUTHORIZATION & TEST CONDITIONS (READ-ONLY)
     ===================================================================== -->
<?php 
if (!empty($req_full) && !empty($req_full->manager_id)): ?>
<div class="section-stage stage-manager">
  <h3 class="section-title stage-title">✓ MANAGER AUTHORIZATION & TEST CONDITIONS (READ ONLY)</h3>
  
  <div class="stage-content">
    <!-- Manager Status & Decision -->
    <div class="subsection-card">
      <h4 class="subsection-header">Authorization Status</h4>
      <table class="readonly-table">
        <tr>
          <th>Approval Status</th>
          <td><strong style="color:<?php echo $req_full->status==='approved'?'#28a745':'#dc3545'; ?>;"><?php echo strtoupper($req_full->status); ?></strong></td>
        </tr>
        <tr>
          <th>Approval Date & Time</th>
          <td><?php echo !empty($req_full->approval_date) ? date('d M Y, H:i', strtotime($req_full->approval_date)) : '—'; ?></td>
        </tr>
        <?php if (!empty($req_full->manager_comment)): ?>
        <tr>
          <th style="vertical-align:top;">Manager's Comment</th>
          <td><?php echo nl2br(esc_html($req_full->manager_comment)); ?></td>
        </tr>
        <?php endif; ?>
      </table>
    </div>

    <!-- Environmental Requirements -->
    <div class="subsection-card" style="margin-top:20px;">
      <h4 class="subsection-header">Environmental Requirements</h4>
      <table class="readonly-table">
        <tr>
          <th>Vacuum Requirement</th>
          <td><?php echo !empty($req_full->env_vacuum) ? esc_html($req_full->env_vacuum) : '—'; ?></td>
        </tr>
        <tr>
          <th>Shroud Temperature</th>
          <td><?php echo !empty($req_full->env_shroud_temp) ? esc_html($req_full->env_shroud_temp) : '—'; ?></td>
        </tr>
        <tr>
          <th>Solar Beam</th>
          <td><?php echo !empty($req_full->env_solar_beam) ? esc_html($req_full->env_solar_beam) : '—'; ?></td>
        </tr>
        <tr>
          <th>Eclipse Simulation</th>
          <td><?php echo !empty($req_full->env_eclipse) ? esc_html($req_full->env_eclipse) : '—'; ?></td>
        </tr>
        <tr>
          <th>Motion - Tilt</th>
          <td><?php echo !empty($req_full->env_motion_tilt) ? esc_html($req_full->env_motion_tilt) : '—'; ?></td>
        </tr>
        <tr>
          <th>Motion - Spin</th>
          <td><?php echo !empty($req_full->env_motion_spin) ? esc_html($req_full->env_motion_spin) : '—'; ?></td>
        </tr>
        <tr>
          <th>Motion Speed</th>
          <td><?php echo !empty($req_full->env_motion_speed) ? esc_html($req_full->env_motion_speed) : '—'; ?></td>
        </tr>
        <tr>
          <th>Mechanical Requirements</th>
          <td><?php echo !empty($req_full->env_mechanical) ? esc_html($req_full->env_mechanical) : '—'; ?></td>
        </tr>
        <tr>
          <th>Key Characteristics</th>
          <td><?php echo !empty($req_full->env_key_char) ? esc_html($req_full->env_key_char) : '—'; ?></td>
        </tr>
      </table>
    </div>

    <?php if (!empty($req_full->qa_reviewer_name)): ?>
    <!-- QA Review Summary -->
    <div class="subsection-card" style="margin-top:20px;">
      <h4 class="subsection-header">QA / T&E Engineer Review</h4>
      <table class="readonly-table">
        <tr>
          <th>Reviewed By</th>
          <td><?php echo esc_html($req_full->qa_reviewer_name); ?></td>
        </tr>
        <tr>
          <th>Review Date</th>
          <td><?php echo !empty($req_full->qa_review_date) ? date('d M Y, H:i', strtotime($req_full->qa_review_date)) : '—'; ?></td>
        </tr>
        <tr>
          <th>Decision</th>
          <td><strong style="color:<?php echo $req_full->qa_decision==='accept'?'#28a745':'#fd7e14'; ?>;"><?php echo $req_full->qa_decision === 'accept' ? '✓ Accepted' : '✗ Rejected'; ?></strong></td>
        </tr>
        <?php if (!empty($req_full->qa_remarks)): ?>
        <tr>
          <th style="vertical-align:top;">QA Remarks</th>
          <td><?php echo nl2br(esc_html($req_full->qa_remarks)); ?></td>
        </tr>
        <?php endif; ?>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<hr class="section-divider">
<?php 
endif;
?>

<!-- =====================================================================
     STAGE 4: LSSC STAFF – FINAL TEST EXECUTION ENTRY (EDITABLE)
     ===================================================================== -->
<div class="section-stage stage-lssc-execution">
  <h3 class="section-title stage-title" style="color:<?php echo ($fd->status === 'completed' || $fd->status === 'Completed') ? '#999' : '#dc3545'; ?>;">✏ LSSC STAFF – FINAL TEST EXECUTION ENTRY</h3>
  
  <div class="stage-content">
    <?php if($fd->status === 'completed' || $fd->status === 'Completed'): ?>
    <div style="background:#e3f2fd;border:2px solid #1976d2;padding:20px;border-radius:6px;margin-bottom:20px;color:#0d47a1;">
      <strong>🔒 Form is Completed and Locked</strong><br>
      <small>This form was submitted and completed. All data is now read-only for reference only.</small>
    </div>
    <?php else: ?>
    <div style="background:#fff3e0;border:2px solid #ff9800;padding:20px;border-radius:6px;margin-bottom:20px;font-size:14px;">
      <strong style="color:#e65100;">📝 Staff Entry Instructions:</strong><br>
      <small style="color:#e65100;">Fill in all required fields below to complete the test execution record. Fields marked with * are mandatory. Save your draft anytime or submit for final processing.</small>
    </div>
    <?php endif; ?>

<form method="post" data-form-status="<?php echo esc_attr($fd->status ?? 'approved'); ?>" data-logged-in-name="<?php echo esc_attr($emp->name ?? ''); ?>">
<?php wp_nonce_field('lssc_action','lssc_nonce'); ?>
<input type="hidden" name="form_id" value="<?php echo $fd->id; ?>">

<!-- Request Summary (Read-Only Reference) -->
<div class="staff-reference-section">
  <h4 class="subsection-header" style="border-bottom:2px solid #ddd;padding-bottom:8px;margin-bottom:15px;">Request Summary</h4>
  <table style="width:100%;border-collapse:collapse;font-size:13px;">
    <tr>
      <td style="padding:8px 12px;"><strong>Test Object:</strong></td>
      <td style="padding:8px 12px;"><?php echo esc_html($fd->satellite_name); ?></td>
      <td style="padding:8px 12px;"><strong>Project:</strong></td>
      <td style="padding:8px 12px;"><?php echo esc_html($fd->project_program); ?></td>
    </tr>
    <tr style="background:#f9f9f9;">
      <td style="padding:8px 12px;"><strong>Test Type:</strong></td>
      <td style="padding:8px 12px;"><?php echo esc_html($fd->test_type); ?></td>
      <td style="padding:8px 12px;"><strong>Test Required By:</strong></td>
      <td style="padding:8px 12px;"><?php echo !empty($fd->test_required_on) ? date('d M Y', strtotime($fd->test_required_on)) : '—'; ?></td>
    </tr>
  </table>
</div>

<hr style="margin:20px 0;border:none;border-top:1px solid #ddd;">

<!-- Staff Entry Form -->

<div class="subsection-card">
  <h4 class="subsection-header">Pre-Test Checks</h4>
  <table class="staff-entry-table">
    <tr>
      <td style="font-weight:600;padding:12px 0;border-bottom:1px solid #ddd;">Requisition Received on <em style="color:#dc3545;">*</em></td>
    </tr>
    <tr>
      <td style="padding:10px 0;"><input type="date" class="form-input-full" name="requisition_received_date" value="<?php echo esc_attr($fd->requisition_received_date??'');?>" <?php echo ($fd->status === 'completed' || $fd->status === 'Completed') ? 'readonly disabled' : ''; ?>></td>
    </tr>
    <tr>
      <td style="font-weight:600;padding:12px 0;border-bottom:1px solid #ddd;border-top:1px solid #ddd;">Risk Assessment Status</td>
    </tr>
    <tr>
      <td style="padding:10px 0;"><input type="text" class="form-input-full" name="risk_assessed_lssc" placeholder="Yes/No" value="<?php echo esc_attr($fd->risk_assessed_lssc??'');?>" data-validation="yes-no" <?php echo ($fd->status === 'completed' || $fd->status === 'Completed') ? 'readonly disabled' : ''; ?> required></td>
    </tr>
    <tr>
      <td style="font-weight:600;padding:12px 0;border-bottom:1px solid #ddd;border-top:1px solid #ddd;">Risk Priority Number (RPN) <em style="color:#999;font-size:12px;font-weight:normal;">(≥5 requires Risk Assessment Form)</em></td>
    </tr>
    <tr>
      <td style="padding:10px 0;"><input type="number" class="form-input-full" name="rpn_lssc" placeholder="Enter RPN (0-25)" min="0" max="25" value="<?php echo esc_attr($fd->rpn_lssc??'');?>" data-validation="rpn" <?php echo ($fd->status === 'completed' || $fd->status === 'Completed') ? 'readonly disabled' : ''; ?>></td>
    </tr>
    <tr>
      <td style="font-weight:600;padding:12px 0;border-bottom:1px solid #ddd;border-top:1px solid #ddd;">Risk Assessment Form Filled? <em style="color:#dc3545;">*</em></td>
    </tr>
    <tr>
      <td style="padding:10px 0;"><input type="text" class="form-input-full" name="risk_form_filled" placeholder="Yes/No" value="<?php echo esc_attr($fd->risk_form_filled??'');?>" data-validation="yes-no" data-rpn-dependent="true" <?php echo ($fd->status === 'completed' || $fd->status === 'Completed') ? 'readonly disabled' : ''; ?> required></td>
    </tr>
    <tr>
      <td style="font-weight:600;padding:12px 0;border-bottom:1px solid #ddd;border-top:1px solid #ddd;">Special Processes Involved? <em style="color:#999;font-size:12px;font-weight:normal;">(Follow URSC-QP-8512 if Yes)</em></td>
    </tr>
    <tr>
      <td style="padding:10px 0;"><input type="text" class="form-input-full" name="special_processes" placeholder="Yes/No" value="<?php echo esc_attr($fd->special_processes??'');?>" data-validation="yes-no" <?php echo ($fd->status === 'completed' || $fd->status === 'Completed') ? 'readonly disabled' : ''; ?>></td>
    </tr>
    <tr>
      <td style="font-weight:600;padding:12px 0;border-bottom:1px solid #ddd;border-top:1px solid #ddd;">Test Received and Reviewed? <em style="color:#dc3545;">*</em></td>
    </tr>
    <tr>
      <td style="padding:10px 0;"><input type="text" class="form-input-full" name="test_received_reviewed" placeholder="Yes/No" value="<?php echo esc_attr($fd->test_received_reviewed??'');?>" data-validation="yes-no" <?php echo ($fd->status === 'completed' || $fd->status === 'Completed') ? 'readonly disabled' : ''; ?> required></td>
    </tr>
    <tr>
      <td style="font-weight:600;padding:12px 0;border-bottom:1px solid #ddd;border-top:1px solid #ddd;">Test Object Accepted for Testing? <em style="color:#dc3545;">*</em></td>
    </tr>
    <tr>
      <td style="padding:10px 0;"><input type="text" class="form-input-full" name="test_object_accepted" placeholder="Yes/No" value="<?php echo esc_attr($fd->test_object_accepted??'');?>" data-validation="yes-no" <?php echo ($fd->status === 'completed' || $fd->status === 'Completed') ? 'readonly disabled' : ''; ?> required></td>
    </tr>
    <tr>
      <td style="font-weight:600;padding:12px 0;border-bottom:1px solid #ddd;border-top:1px solid #ddd;">Test Request Accepted by <em style="color:#999;font-size:12px;font-weight:normal;">(Dy. Manager/Competent Authority)</em></td>
    </tr>
    <tr>
      <td style="padding:10px 0;"><input type="text" class="form-input-full" name="test_accepted_by" placeholder="Name/Designation" value="<?php echo esc_attr($fd->test_accepted_by??'');?>" <?php echo ($fd->status === 'completed' || $fd->status === 'Completed') ? 'readonly disabled' : ''; ?>></td>
    </tr>
  </table>
</div>

<div class="subsection-card" style="margin-top:20px;">
  <h4 class="subsection-header">Test Execution Details</h4>
  <table class="staff-entry-table">
    <tr>
      <td style="font-weight:600;padding:12px 0;border-bottom:1px solid #ddd;">Test Started on <em style="color:#dc3545;">*</em></td>
    </tr>
    <tr>
      <td style="padding:10px 0;"><input type="datetime-local" class="form-input-full" name="test_started_datetime" value="<?php echo esc_attr(str_replace(' ', 'T', $fd->test_started_datetime??''));?>" <?php echo ($fd->status === 'completed' || $fd->status === 'Completed') ? 'readonly disabled' : ''; ?> required></td>
    </tr>
    <tr>
      <td style="font-weight:600;padding:12px 0;border-bottom:1px solid #ddd;border-top:1px solid #ddd;">Test Completed on <em style="color:#dc3545;">*</em></td>
    </tr>
    <tr>
      <td style="padding:10px 0;"><input type="datetime-local" class="form-input-full" name="test_completed_datetime" value="<?php echo esc_attr(str_replace(' ', 'T', $fd->test_completed_datetime??''));?>" data-validation="must-after-start" <?php echo ($fd->status === 'completed' || $fd->status === 'Completed') ? 'readonly disabled' : ''; ?> required></td>
    </tr>
    <tr>
      <td style="font-weight:600;padding:12px 0;border-bottom:1px solid #ddd;border-top:1px solid #ddd;">Test Duration <em style="color:#999;font-size:12px;font-weight:normal;">(auto-calculated)</em></td>
    </tr>
    <tr>
      <td style="padding:10px 0;"><input type="text" class="form-input-full" name="test_duration" placeholder="HH:MM:SS" value="<?php echo esc_attr($fd->test_duration??'');?>" readonly style="background-color:#f5f5f5;cursor:not-allowed;"></td>
    </tr>
    <tr>
      <td style="font-weight:600;padding:12px 0;border-bottom:1px solid #ddd;border-top:1px solid #ddd;">Test Completed On-Time?</td>
    </tr>
    <tr>
      <td style="padding:10px 0;"><input type="text" class="form-input-full" name="test_on_time" placeholder="Yes/No" value="<?php echo esc_attr($fd->test_on_time??'');?>" data-validation="yes-no" <?php echo ($fd->status === 'completed' || $fd->status === 'Completed') ? 'readonly disabled' : ''; ?>></td>
    </tr>
  </table>
</div>

<div class="subsection-card" style="margin-top:20px;">
  <h4 class="subsection-header">Specimen Collection & Verification</h4>
  <table class="staff-entry-table">
    <tr>
      <td style="font-weight:600;padding:12px 0;border-bottom:1px solid #ddd;">Test Specimen Collected by</td>
    </tr>
    <tr>
      <td style="padding:6px 0;">
        <input type="text" class="form-input-full" name="specimen_collected_by_name" placeholder="Your name (auto-fills)" value="<?php echo esc_attr($fd->specimen_collected_by_name??'');?>" data-auto-fill="logged-in-name" <?php echo ($fd->status === 'completed' || $fd->status === 'Completed') ? 'readonly disabled' : ''; ?> required>
        <small style="color:#666;display:block;margin-top:4px;">Will auto-fill with your name if left empty</small>
      </td>
    </tr>
    <tr>
      <td style="font-weight:600;padding:12px 0;border-bottom:1px solid #ddd;border-top:1px solid #ddd;">Specimen Collector Signature <em style="color:#999;font-size:12px;font-weight:normal;">(auto-fills if empty)</em></td>
    </tr>
    <tr>
      <td style="padding:6px 0;">
        <input type="text" class="form-input-full" placeholder="Name - Timestamp" value="<?php echo esc_attr($fd->specimen_collected_by_sig??'');?>" name="specimen_collected_by_sig" data-auto-fill="signature" <?php echo ($fd->status === 'completed' || $fd->status === 'Completed') ? 'readonly disabled' : ''; ?> required>
        <small style="color:#666;display:block;margin-top:4px;">Auto-fills with name & timestamp if left empty</small>
      </td>
    </tr>
    <tr>
      <td style="font-weight:600;padding:12px 0;border-bottom:1px solid #ddd;border-top:1px solid #ddd;">Verification & Requisition Closed by <em style="color:#999;font-size:12px;font-weight:normal;">(Dy. Manager/Competent Authority)</em></td>
    </tr>
    <tr>
      <td style="padding:6px 0;">
        <input type="text" class="form-input-full" name="verification_closed_by_name" placeholder="Your name (auto-fills)" value="<?php echo esc_attr($fd->verification_closed_by_name??'');?>" data-auto-fill="logged-in-name" <?php echo ($fd->status === 'completed' || $fd->status === 'Completed') ? 'readonly disabled' : ''; ?> required>
        <small style="color:#666;display:block;margin-top:4px;">Will auto-fill with your name if left empty</small>
      </td>
    </tr>
    <tr>
      <td style="font-weight:600;padding:12px 0;border-bottom:1px solid #ddd;border-top:1px solid #ddd;">Verification Signature <em style="color:#999;font-size:12px;font-weight:normal;">(auto-fills if empty)</em></td>
    </tr>
    <tr>
      <td style="padding:6px 0;">
        <input type="text" class="form-input-full" placeholder="Name - Timestamp" value="<?php echo esc_attr($fd->verification_closed_by_sig??'');?>" name="verification_closed_by_sig" data-auto-fill="signature" <?php echo ($fd->status === 'completed' || $fd->status === 'Completed') ? 'readonly disabled' : ''; ?> required>
        <small style="color:#666;display:block;margin-top:4px;">Auto-fills with name & timestamp if left empty</small>
      </td>
    </tr>
  </table>
</div>

<!-- BUTTON SECTION -->
<div style="margin-top:40px;padding-top:30px;border-top:2px solid #ddd;text-align:right;display:flex;justify-content:flex-end;gap:15px;flex-wrap:wrap;">
  <button type="submit" name="save_draft" class="btn-draft-final" <?php echo ($fd->status === 'Completed' || $fd->status === 'completed') ? 'disabled' : ''; ?>>💾 Save Draft</button>
  <button type="submit" name="complete_lssc" class="btn-complete-final" <?php echo ($fd->status === 'Completed' || $fd->status === 'completed') ? 'disabled' : ''; ?>>✓ Final Submit</button>
</div>
</form>
  </div>
</div>

<?php
else:
    echo "<p style='text-align:center;color:#dc3545;padding:50px;'>Form not found or not approved.</p>";
endif;
// ========== EMBEDDED CSS & JAVASCRIPT ========== 
?>
<style>
/* ========== LSSC HIERARCHICAL LAYOUT STYLES ========== */
.lssc-hierarchical-layout {
  max-width:100%;
  margin:0;
  padding:0;
}

/* Section Stages - Main Containers */
.section-stage {
  background:#fff;
  border:1px solid #ddd;
  border-radius:6px;
  padding:25px;
  margin:20px 0;
  transition:all 0.3s ease;
}

.section-stage:hover {
  box-shadow:0 2px 8px rgba(0,0,0,0.08);
}

/* Stage-specific styling */
.stage-pipeline {
  background:#f0f7ff;
  border-left:4px solid #2196f3;
}

.stage-indenter {
  background:#f8f9fa;
  border-left:4px solid #6c757d;
}

.stage-manager {
  background:#e3f2fd;
  border-left:4px solid #1976d2;
}

.stage-lssc-execution {
  background:#fff;
  border-left:4px solid #dc3545;
  border:1px solid #ddd;
}

/* Section Headers */
.section-title {
  font-size:16px;
  font-weight:700;
  margin:0 0 20px 0;
  padding:0 0 15px 0;
  border-bottom:2px solid #ddd;
  color:#333;
  display:flex;
  align-items:center;
  gap:8px;
}

.stage-title {
  border-bottom-width:2px !important;
}

.stage-pipeline .section-title { color:#1565c0; border-bottom-color:#2196f3; }
.stage-indenter .section-title { color:#495057; border-bottom-color:#6c757d; }
.stage-manager .section-title { color:#0d47a1; border-bottom-color:#1976d2; }
.stage-lssc-execution .section-title { color:#c41c3b; border-bottom-color:#dc3545; }

.stage-content {
  margin-top:15px;
}

/* Section Dividers */
.section-divider {
  border:none;
  border-top:2px solid #ddd;
  margin:30px 0;
  padding:0;
}

/* Subsection Cards */
.subsection-card {
  background:#fff;
  border:1px solid #e0e0e0;
  border-radius:4px;
  padding:18px;
  margin:0 0 15px 0;
}

.stage-indenter .subsection-card,
.stage-manager .subsection-card {
  background:#fafbfc;
  border-left:3px solid #6c757d;
}

.stage-manager .subsection-card {
  background:#f5f9fc;
  border-left-color:#1976d2;
}

.subsection-header {
  font-size:14px;
  font-weight:600;
  margin:0 0 12px 0;
  color:#333;
  border-bottom:1px solid #e0e0e0;
  padding-bottom:8px;
  display:flex;
  align-items:center;
  gap:6px;
}

/* Read-Only Tables */
.readonly-table {
  width:100%;
  border-collapse:collapse;
  font-size:13px;
  line-height:1.6;
}

.readonly-table tr:nth-child(even) {
  background:#f9f9fa;
}

.readonly-table th {
  background:#f5f5f5;
  border:1px solid #ddd;
  padding:12px;
  text-align:left;
  font-weight:600;
  color:#333;
  width:30%;
  vertical-align:top;
}

.readonly-table td {
  border:1px solid #ddd;
  padding:12px;
  color:#555;
  word-wrap:break-word;
}

.readonly-table td em {
  color:#999;
  font-style:italic;
}

/* Staff Entry Styles */
.staff-reference-section {
  background:#f9f9f9;
  border:1px solid #e0e0e0;
  border-radius:4px;
  padding:16px;
  margin-bottom:20px;
}

.staff-entry-table {
  width:100%;
  border-collapse:collapse;
  font-size:13px;
}

.staff-entry-table tr td {
  padding:0;
  border:none;
}

.staff-entry-table tr:nth-child(2n) td {
  background:transparent;
}

.form-input-full {
  width:100%;
  padding:10px 12px;
  border:1px solid #ccc;
  border-radius:4px;
  font-size:13px;
  font-family:inherit;
  transition:all 0.2s ease;
  box-sizing:border-box;
  background:#fff;
}

.form-input-full:focus {
  outline:none;
  border-color:#2196f3;
  box-shadow:0 0 0 3px rgba(33,150,243,0.1);
}

.form-input-full:disabled,
.form-input-full[readonly] {
  background:#f5f5f5;
  color:#666;
  cursor:not-allowed;
  border-color:#ddd;
}

input[type="date"],
input[type="datetime-local"] {
  font-family:inherit;
}

/* Draft & Submit Buttons */
.btn-draft-final,
.btn-complete-final {
  padding:12px 24px;
  border:none;
  border-radius:4px;
  font-size:14px;
  font-weight:600;
  cursor:pointer;
  transition:all 0.2s ease;
  white-space:nowrap;
}

.btn-draft-final {
  background:#6c757d;
  color:#fff;
}

.btn-draft-final:hover:not(:disabled) {
  background:#5a6268;
  box-shadow:0 2px 8px rgba(0,0,0,0.15);
}

.btn-complete-final {
  background:#dc3545;
  color:#fff;
}

.btn-complete-final:hover:not(:disabled) {
  background:#c82333;
  box-shadow:0 2px 8px rgba(0,0,0,0.15);
}

.btn-draft-final:disabled,
.btn-complete-final:disabled {
  opacity:0.5;
  cursor:not-allowed;
  box-shadow:none;
}

/* Status Badges */
.status-badge-draft {
  display:inline-block;
  padding:8px 12px;
  background:#ffc107;
  color:#333;
  font-weight:600;
  border-radius:4px;
  font-size:12px;
}

.status-badge-completed {
  display:inline-block;
  padding:8px 12px;
  background:#28a745;
  color:#fff;
  font-weight:600;
  border-radius:4px;
  font-size:12px;
}

/* Responsive Design */
@media (max-width:768px) {
  .section-stage {
    padding:18px;
  }
  
  .subsection-card {
    padding:14px;
  }
  
  .readonly-table th {
    width:auto;
  }
  
  .section-title {
    font-size:15px;
  }
  
  .subsection-header {
    font-size:13px;
  }
  
  .form-input-full {
    font-size:16px; /* Prevents mobile zoom on iOS */
  }
}

/* Print Styles */
@media print {
  .section-divider {
    page-break-inside:avoid;
  }
  
  .section-stage {
    page-break-inside:avoid;
    box-shadow:none;
  }
}
</style>

<script>
/**
 * LSSC Form Enhancement - Embedded Validation & Auto-Fill
 * Features:
 * - Auto-calculate Test Duration (HH:MM:SS)
 * - Validate test dates (Completed >= Started)
 * - Lock form if status = COMPLETED
 * - Auto-fill signatures with staff name & timestamp
 * - RPN >= 5 validation (Risk Assessment Form required)
 * - Mandatory field validation on complete submit
 */

document.addEventListener('DOMContentLoaded', function() {
    const formStatus = document.querySelector('[data-form-status]')?.getAttribute('data-form-status') || '';
    const loggedInName = document.querySelector('[data-logged-in-name]')?.getAttribute('data-logged-in-name') || '';
    
    // 1. LOCK FORM IF COMPLETED
    if (formStatus === 'completed' || formStatus === 'Completed') {
        lockFormForCompletion();
    }

    // 2. TEST DURATION AUTO-CALCULATION
    const testStarted = document.querySelector('[name="test_started_datetime"]');
    const testCompleted = document.querySelector('[name="test_completed_datetime"]');
    const testDuration = document.querySelector('[name="test_duration"]');
    
    if (testStarted && testCompleted && testDuration) {
        testDuration.setAttribute('readonly', true);
        testDuration.style.backgroundColor = '#f5f5f5';
        testDuration.style.cursor = 'not-allowed';
        
        [testStarted, testCompleted].forEach(el => {
            el.addEventListener('change', calculateDuration);
        });
    }

    // 3. AUTO-FILL SIGNATURES
    if (loggedInName) {
        const form = document.querySelector('form');
        if (form) {
            form.addEventListener('submit', function(e) {
                const specCollectedName = document.querySelector('[name="specimen_collected_by_name"]');
                const specCollectedSig = document.querySelector('[name="specimen_collected_by_sig"]');
                const verifyClosedName = document.querySelector('[name="verification_closed_by_name"]');
                const verifyClosedSig = document.querySelector('[name="verification_closed_by_sig"]');
                
                if (specCollectedName && !specCollectedName.value.trim()) {
                    specCollectedName.value = loggedInName;
                }
                if (specCollectedSig && !specCollectedSig.value.trim()) {
                    specCollectedSig.value = loggedInName + ' - ' + getCurrentTimestamp();
                }
                if (verifyClosedName && !verifyClosedName.value.trim()) {
                    verifyClosedName.value = loggedInName;
                }
                if (verifyClosedSig && !verifyClosedSig.value.trim()) {
                    verifyClosedSig.value = loggedInName + ' - ' + getCurrentTimestamp();
                }
            });
        }
    }

    // 4. VALIDATION ON COMPLETE SUBMIT
    const completeBtn = document.querySelector('[name="complete_lssc"]');
    if (completeBtn && !completeBtn.disabled) {
        completeBtn.addEventListener('click', function(e) {
            const errors = validateLSSCForm();
            if (errors.length > 0) {
                e.preventDefault();
                showValidationErrors(errors);
            }
        });
    }
});

function calculateDuration() {
    const testStarted = document.querySelector('[name="test_started_datetime"]');
    const testCompleted = document.querySelector('[name="test_completed_datetime"]');
    const testDuration = document.querySelector('[name="test_duration"]');
    
    if (!testStarted.value || !testCompleted.value) {
        testDuration.value = '';
        return;
    }
    
    const startTime = new Date(testStarted.value.replace('T', ' '));
    const endTime = new Date(testCompleted.value.replace('T', ' '));
    
    if (endTime < startTime) {
        showInlineError(testCompleted, 'Test Completed date must be after Test Started date');
        testDuration.value = '';
        return;
    } else {
        clearInlineError(testCompleted);
    }
    
    const diffMs = endTime - startTime;
    const hours = Math.floor(diffMs / (1000 * 60 * 60));
    const mins = Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60));
    const secs = Math.floor((diffMs % (1000 * 60)) / 1000);
    
    testDuration.value = 
        String(hours).padStart(2, '0') + ':' + 
        String(mins).padStart(2, '0') + ':' + 
        String(secs).padStart(2, '0');
}

function validateRPNDependency() {
    const rpnLssc = document.querySelector('[name="rpn_lssc"]');
    const riskFormFilled = document.querySelector('[name="risk_form_filled"]');
    
    if (!rpnLssc || !riskFormFilled) return true;
    
    const rpnVal = parseInt(rpnLssc.value, 10) || 0;
    const riskVal = riskFormFilled.value.toLowerCase().trim();
    
    if (rpnVal >= 5 && riskVal !== 'yes') {
        showInlineError(riskFormFilled, 'If RPN ≥ 5, Risk Assessment Form must be "Yes"');
        return false;
    }
    
    clearInlineError(riskFormFilled);
    return true;
}

function validateLSSCForm() {
    const errors = [];
    
    // Mandatory fields
    const mandatoryFields = [
        { name: 'requisition_received_date', label: 'Requisition Received Date' },
        { name: 'risk_assessed_lssc', label: 'Risk Assessed (Yes/No)' },
        { name: 'test_started_datetime', label: 'Test Started Date/Time' },
        { name: 'test_completed_datetime', label: 'Test Completed Date/Time' },
        { name: 'specimen_collected_by_name', label: 'Specimen Collected by Name' },
        { name: 'verification_closed_by_name', label: 'Verification Closed by Name' }
    ];
    
    mandatoryFields.forEach(field => {
        const el = document.querySelector(`[name="${field.name}"]`);
        if (!el || !el.value.trim()) {
            errors.push(`${field.label} is required`);
        }
    });
    
    // Date validation
    const testStarted = document.querySelector('[name="test_started_datetime"]');
    const testCompleted = document.querySelector('[name="test_completed_datetime"]');
    
    if (testStarted && testCompleted && testStarted.value && testCompleted.value) {
        const startTime = new Date(testStarted.value.replace('T', ' '));
        const endTime = new Date(testCompleted.value.replace('T', ' '));
        
        if (endTime < startTime) {
            errors.push('Test Completed date/time must be after Test Started date/time');
        }
    }
    
    // Yes/No validation
    const yesNoFields = ['risk_assessed_lssc', 'risk_form_filled', 'special_processes', 
                         'test_received_reviewed', 'test_object_accepted', 'test_on_time'];
    
    yesNoFields.forEach(fieldName => {
        const el = document.querySelector(`[name="${fieldName}"]`);
        if (el && el.value.trim()) {
            const val = el.value.toLowerCase().trim();
            if (val !== 'yes' && val !== 'no') {
                errors.push(`${fieldName}: Please enter Yes or No`);
            }
        }
    });
    
    // RPN >= 5 validation
    if (!validateRPNDependency()) {
        errors.push('If RPN ≥ 5, Risk Assessment Form must be marked "Yes"');
    }
    
    return errors;
}

function lockFormForCompletion() {
    // Disable all form inputs
    const inputs = document.querySelectorAll('form input[type="text"], form input[type="date"], form input[type="datetime-local"], form input[type="number"], form input[type="email"], form textarea, form select, form input[type="radio"], form input[type="checkbox"]');
    const buttons = document.querySelectorAll('form button[type="submit"]');
    
    inputs.forEach(input => {
        input.setAttribute('readonly', true);
        input.setAttribute('disabled', true);
        input.style.backgroundColor = '#f5f5f5';
        input.style.cursor = 'not-allowed';
        input.style.color = '#666';
        input.style.pointerEvents = 'none';
    });
    
    buttons.forEach(btn => {
        btn.style.opacity = '0.5';
        btn.style.cursor = 'not-allowed';
        btn.setAttribute('disabled', true);
        btn.style.pointerEvents = 'none';
    });
}

function showValidationErrors(errors) {
    const existingError = document.querySelector('[data-lssc-error-box]');
    if (existingError) existingError.remove();
    
    const errorBox = document.createElement('div');
    errorBox.setAttribute('data-lssc-error-box', 'true');
    errorBox.style.cssText = 'background:#f8d7da;border:2px solid #dc3545;padding:16px 20px;margin-bottom:20px;border-radius:4px;color:#721c24;';
    
    const errorHtml = '<strong>⚠ Please fix the following errors:</strong><ul style="margin:10px 0 0 20px;padding:0;">' + 
        errors.map(e => '<li>' + e + '</li>').join('') + 
        '</ul>';
    
    errorBox.innerHTML = errorHtml;
    
    const form = document.querySelector('form');
    if (form) {
        form.parentNode.insertBefore(errorBox, form);
        errorBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

function showInlineError(element, message) {
    clearInlineError(element);
    
    const errorEl = document.createElement('div');
    errorEl.className = 'lssc-inline-error';
    errorEl.style.cssText = 'color:#dc3545;font-size:12px;margin-top:4px;font-weight:500;';
    errorEl.textContent = '⚠ ' + message;
    
    element.parentNode.insertBefore(errorEl, element.nextSibling);
    element.style.borderColor = '#dc3545';
    element.style.backgroundColor = '#fff5f5';
}

function clearInlineError(element) {
    const errorEl = element.parentNode.querySelector('.lssc-inline-error');
    if (errorEl) errorEl.remove();
    element.style.borderColor = '';
    element.style.backgroundColor = '';
}

function getCurrentTimestamp() {
    const now = new Date();
    const day = String(now.getDate()).padStart(2, '0');
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const year = now.getFullYear();
    const hours = String(now.getHours()).padStart(2, '0');
    const mins = String(now.getMinutes()).padStart(2, '0');
    return day + '-' + month + '-' + year + ' ' + hours + ':' + mins;
}
</script>

<?php else: ?>
        // Get all records that are either 'approved' (pending staff work), 'Draft', or 'Completed'
        $approved = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE status IN ('approved', 'Draft', 'Completed', 'completed') ORDER BY CASE WHEN status IN ('Draft', 'Completed', 'completed') THEN 0 ELSE 1 END, approval_date DESC"
        ); ?>
<div class="container">
<div class="role-indicator">LSSC STAFF VIEW | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>
<?php $lssc_msg = sanitize_text_field($_GET['lssc_msg'] ?? '');
if ($lssc_msg === 'lssc_draft_saved'): ?>
<div style="background:#d1ecf1;color:#0c5460;border:2px solid #bee5eb;padding:16px 20px;margin-bottom:20px;border-radius:4px;font-weight:500;">
  ✓ <strong>Draft saved successfully.</strong> You can return later to continue editing and make final submission.
</div>
<?php elseif ($lssc_msg === 'lssc_completed'): ?>
<div style="background:#d4edda;color:#155724;border:2px solid #c3e6cb;padding:16px 20px;margin-bottom:20px;border-radius:4px;font-weight:500;">
  ✓ <strong>Test details submitted successfully.</strong> Process completed. The form is now locked and read-only.
</div>
<?php endif; ?>
<h1>LSSC Staff Dashboard</h1>
<p>Total Approved Requests: <strong><?php echo count($approved); ?></strong></p>
<?php if(empty($approved)): ?><div style="text-align:center;padding:80px;color:#666;border:2px solid #ddd;background:#f9f9f9;border-radius:8px;"><h3 style="margin:0 0 10px 0;font-size:18px;color:#333;">NO APPROVED REQUESTS PENDING COMPLETION</h3><p style="margin:0;font-size:15px;">Awaiting manager approval of requests</p></div>
<?php else: ?>
<table class="list-table">
  <thead><tr><th>TR No.</th><th>Satellite/Test Object</th><th>Project</th><th>Approved Date</th><th>Status</th><th>Last Modified</th><th>Action</th></tr></thead>
  <tbody>
  <?php foreach($approved as $req):
    $current_status = strtolower($req->status ?? 'approved');
    $status_badge = 'badge-pending';
    $status_text = 'Pending';
    if ($current_status === 'draft' || $current_status === 'Draft') {
        $status_badge = 'badge-pending';
        $status_text = 'Draft (In Progress)';
    } elseif ($current_status === 'completed') {
        $status_badge = 'badge-completed';
        $status_text = 'Completed';
    } elseif ($current_status === 'approved') {
        $status_badge = 'badge-pending';
        $status_text = 'Not Started';
    }
  ?>
  <tr>
    <td><strong><?php echo esc_html($req->test_requisition_no); ?></strong></td>
    <td><?php echo esc_html($req->satellite_name); ?></td>
    <td><?php echo esc_html($req->project_program); ?></td>
    <td><?php echo date('d M Y, h:i A', strtotime($req->approval_date)); ?></td>
    <td><span class="badge <?php echo $status_badge; ?>" style="padding:6px 12px;font-size:11px;"><?php echo esc_html($status_text); ?></span></td>
    <td><?php echo !empty($req->draft_saved_at) ? date('d M Y, h:i A', strtotime($req->draft_saved_at)) : '—'; ?></td>
    <td><a href="<?php echo esc_url(add_query_arg('complete_id',$req->id)); ?>" class="btn btn-view btn-test-details"><?php echo ($current_status === 'draft' || $current_status === 'Draft') ? 'Continue' : 'View/Edit'; ?></a></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
</div>
<?php
}
?>
// ========== MANAGER REVIEW FORM CONTROL ==========
document.addEventListener('DOMContentLoaded', function() {
    // Initialize manager review form control
    const envTable = document.getElementById('env_requirements_table');
    const mgrFinalComment = document.getElementById('mgr_final_comment');
    const approveBtn = document.querySelector('button[value="approve"]');
    const rejectBtn = document.querySelector('button[value="reject"]');
    const recheckBtn = document.querySelector('button[value="recheck"]');
    
    if (!envTable || !mgrFinalComment) return;
    
    // Function to enable/disable table fields
    function setTableState(enabled) {
        const inputs = envTable.querySelectorAll('.env-spec-input, .env-comment-input');
        inputs.forEach(input => {
            if (enabled) {
                input.disabled = false;
                input.style.background = '#fff';
                input.style.cursor = 'text';
            } else {
                input.disabled = true;
                input.style.background = '#f5f5f5';
                input.style.cursor = 'not-allowed';
            }
        });
    }
    
    // Function to update status message
    function updateStatusMessage(action) {
        const statusSpan = document.getElementById('mgr_approval_status');
        if (!statusSpan) return;
        
        const messages = {
            'approve': '✓ APPROVE mode: Environmental Requirements fields are ENABLED for editing. Fill in all required fields.',
            'reject': '✗ REJECT mode: Environmental Requirements are READ-ONLY. Your comment explains the rejection.',
            'recheck': '⟲ RECHECK mode: Environmental Requirements are READ-ONLY. Your comment explains required changes.'
        };
        
        statusSpan.textContent = messages[action] || 'Select an action';
        statusSpan.style.color = action === 'approve' ? '#28a745' : (action === 'reject' ? '#dc3545' : '#ffc107');
        statusSpan.style.fontWeight = '600';
    }
    
    // Add click handler to APPROVE button
    if (approveBtn) {
        approveBtn.addEventListener('click', function(e) {
            setTableState(true);
            updateStatusMessage('approve');
            // Don't require comment for approve
        });
    }
    
    // Add click handler to REJECT button
    if (rejectBtn) {
        rejectBtn.addEventListener('click', function(e) {
            setTableState(false);
            updateStatusMessage('reject');
            // Validate comment is required
            const comment = mgrFinalComment?.value.trim();
            if (!comment) {
                e.preventDefault();
                alert('⚠ Comment is MANDATORY when REJECTING.\n\nPlease explain the reason for rejection in the "Manager Decision Comments" field at the top.');
                mgrFinalComment?.focus();
            }
        });
    }
    
    // Add click handler to RECHECK button
    if (recheckBtn) {
        recheckBtn.addEventListener('click', function(e) {
            setTableState(false);
            updateStatusMessage('recheck');
            // Validate comment is required
            const comment = mgrFinalComment?.value.trim();
            if (!comment) {
                e.preventDefault();
                alert('⚠ Comment is MANDATORY when REQUESTING RECHECK.\n\nPlease explain the required changes in the "Manager Decision Comments" field at the top.');
                mgrFinalComment?.focus();
            }
        });
    }
    
    // Initialize with disabled state
    setTableState(false);
    updateStatusMessage('');
});

// ========== ORIGINAL MANAGER SUBMIT FUNCTION (for backward compatibility) ==========
function lssc_manager_submit(e) {
    e.preventDefault();
    document.body.style.opacity = '0.6';
    document.body.style.pointerEvents = 'none';
    
    const form = e.target;
    const formData = new FormData(form);
    const actionType = document.activeElement.value;
    
    formData.append('action', 'lssc_manager_approval');
    formData.append('action_type', actionType);
    
    fetch(<?php echo json_encode(admin_url('admin-ajax.php')); ?>, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        document.body.style.opacity = '1';
        document.body.style.pointerEvents = 'auto';
        
        if (data.success) {
            alert(data.data.message);
            location.reload();
        } else {
            alert('Error: ' + (data.data || 'Unknown error'));
        }
    })
    .catch(error => {
        document.body.style.opacity = '1';
        document.body.style.pointerEvents = 'auto';
        alert('Error submitting form: ' + error.message);
    });
    
    return false;
}
</script>
<?php get_footer(); ?>