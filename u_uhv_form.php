<?php
/*
Template Name: uhv_form
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
$table = $wpdb->prefix . 'uhv_form';

$emp = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}employee_table_2 WHERE email = %s", $user->user_email
));
if (!$emp) {
    get_header();
    echo "<p style='color:#dc3545;text-align:center;padding:40px;font-size:16px;'>Employee record not found.</p>";
    get_footer(); exit;
}

// ========== ROLE DETERMINATION ==========
// Aligned with LSSC/CATVAC: any logged-in employee may submit a TR; section flag gates staff (phase-1) form.
$user_role = 'none';
$funcdesg  = strtoupper(trim($emp->funcdesg ?? ''));
$desgn     = strtoupper(trim($emp->desgn ?? ''));
$section   = strtoupper(trim($emp->sectionfullname ?? ''));
$division  = strtoupper(trim($emp->divisionfullname ?? ''));

$is_uhv_section_person = (strpos($section, 'ULTRA HIGH VACCUM') !== false || strpos($division, 'LARGE SPACE') !== false);

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

// ── UHV STAFF (technicians in LARGE SPACE section/division)
if ($user_role === 'none') {
    $uhv_desgs   = ['TECHNICAL ASSISTANT','SR. TECHNICAL ASST.-A','SR. TECHNICAL ASST',
        'TECHNICIAN-F','TECHNICIAN-G','TECHNICIAN-D','TECHNICIAN-B','TECHNICIAN',
        'TECHNICAL OFFICER-C','TECHNICAL OFFICER-D','TECHNICAL OFFICER-E','TECHNICAL OFFICER',
        'ASSISTANT ENGINEER','JUNIOR ENGINEER'];
    $is_uhv_desg = false;
    foreach ($uhv_desgs as $d) { if (strpos($desgn, $d) !== false) { $is_uhv_desg = true; break; } }
    if ($is_uhv_section_person && $is_uhv_desg) {
        $user_role = 'UHV';
    }
}

// ── Any other employee: external / other-section submitter (LSSC-style tr_submitter)
if ($user_role === 'none') {
    $user_role = 'tr_submitter';
}

$GLOBALS['user_role'] = $user_role;
$GLOBALS['is_uhv_section_person'] = $is_uhv_section_person;
$GLOBALS['can_fill_staff_form'] = (
    $user_role === 'UHV'
    || $user_role === 'manager'
    || (in_array($user_role, ['indenter', 'tr_submitter'], true) && $is_uhv_section_person)
);

/** Roles that may create/save/submit the main UHV test request form (not only manager-specific flows). */
function uhv_can_edit_test_request($role) {
    return in_array($role, ['indenter', 'tr_submitter', 'manager', 'UHV'], true);
}

/** Empty string if blank or non-numeric; otherwise normalized number string (allows decimals). */
function uhv_form_sanitize_opt_numeric($post_key) {
    $v = isset($_POST[$post_key]) ? trim(wp_unslash((string) $_POST[$post_key])) : '';
    if ($v === '' || !is_numeric($v)) {
        return '';
    }
    return (string) (0 + (float) $v);
}

// ========== TABLE CREATION ==========
if (!$wpdb->get_var("SHOW TABLES LIKE '$table'")) {
    $wpdb->query("CREATE TABLE $table (
        id INT AUTO_INCREMENT PRIMARY KEY,
        test_requisition_no VARCHAR(50) UNIQUE NOT NULL,
        submission_date DATETIME DEFAULT CURRENT_TIMESTAMP,
        satellite_name TEXT,
        test_type TEXT,
        test_required_on DATE,
        sub_name TEXT,
        sub_stno VARCHAR(50),
        sub_email TEXT,
        sub_section TEXT,
        sub_division TEXT,
        sub_designation TEXT,
        sub_phone VARCHAR(50),
        thermal_power INT DEFAULT 0,
        thermal_thermocouples INT DEFAULT 0,
        ground_dc_signal INT DEFAULT 0,
        ground_dc_signal_comments TEXT,
        ground_signal_power INT DEFAULT 0,
        ground_signal_power_comments TEXT,
        thermal_power_comments TEXT,
        thermal_thermocouples_comments TEXT,
        rf_connector_type TEXT,
        rf_connector_channels INT DEFAULT 0,
        rf_connector_comments TEXT,
        rf_connectors_json LONGTEXT,
        special_requirements LONGTEXT,
        user_id BIGINT,
        status VARCHAR(50) DEFAULT 'draft_indenter',
        indenter_draft_saved_at DATETIME,
        indenter_draft_saved_by VARCHAR(100),
        manager_id BIGINT,
        manager_comment LONGTEXT,
        reviewed_by TEXT,
        approval_date DATETIME,
        risk_assessed_uhv VARCHAR(50),
        rpn_uhv VARCHAR(50),
        test_object_accepted VARCHAR(50),
        risk_record_uhv VARCHAR(20),
        risk_form_file TEXT,
        test_started_datetime DATETIME,
        test_completed_datetime DATETIME,
        test_duration TEXT,
        test_on_time VARCHAR(50),
        specimen_collected_by_name TEXT,
        specimen_collected_by_sig TEXT,
        verification_closed_by_name TEXT,
        verification_closed_by_sig TEXT,
        completion_date DATETIME,
        draft_saved_at DATETIME,
        draft_saved_by VARCHAR(100),
        manager_action VARCHAR(20) DEFAULT '',
        manager_decision_date DATETIME,
        test_types TEXT,
        vcm_temp_cold_bar TEXT,
        msld_samples_json LONGTEXT,
        gauge_calibration_json LONGTEXT,
        corona_test_json LONGTEXT,
        other_special_test_desc TEXT,
        bombing_leak_test_json LONGTEXT,
        bombing_staff_json LONGTEXT,
        per_test_risk_json LONGTEXT,
        test_code VARCHAR(100),
        staff_review_date DATETIME,
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
    'rf_connectors_json'    => 'LONGTEXT',
    'qa_exists'         => "VARCHAR(10) DEFAULT 'no'",
    'qa_name'           => 'VARCHAR(100)',
    'qa_stno'           => 'VARCHAR(50)',
    'qa_section'        => 'TEXT',
    'qa_phone'          => 'VARCHAR(50)',
    'qa_review_date'    => 'DATETIME',
    'qa_reviewer_name'  => 'VARCHAR(100)',
    'qa_remarks'        => 'LONGTEXT',
    'qa_decision'           => "VARCHAR(20) DEFAULT ''",
    'qa_email'              => 'VARCHAR(100)',
    'qa_designation'        => 'VARCHAR(150)',
    'risk_form_file'        => 'TEXT',
    'test_types'            => 'TEXT',
    // Manager two-step flow: decision first, then env form
    'manager_action'        => "VARCHAR(20) DEFAULT ''",  // 'approve','reject','recheck'
    'manager_decision_date' => 'DATETIME',
    'history_log'           => 'LONGTEXT NULL DEFAULT NULL',
    // --- Multipaction Test ---
    'mp_package_size'       => 'TEXT',
    'mp_test_profile_attach'=> 'VARCHAR(20)',
    'mp_test_profile_file'  => 'TEXT',
    'mp_thermocouples'      => 'VARCHAR(50)',
    'mp_ft_rf_qty'          => 'INT DEFAULT 0',
    'mp_ft_elec_qty'        => 'INT DEFAULT 0',
    'mp_ft_others_spec'     => 'TEXT',
    'mp_ft_others_qty'      => 'INT DEFAULT 0',
    'mp_special_instructions'=> 'TEXT',
    // --- Thermal Vacuum Cycling Test ---
    'tvc_specimen_name'     => 'TEXT',
    'tvc_package_size'      => 'TEXT',
    'tvc_vacuum_range'      => 'TEXT',
    'tvc_temp_hot'          => 'VARCHAR(50)',
    'tvc_temp_hot_tol'      => 'VARCHAR(50)',
    'tvc_temp_cold'         => 'VARCHAR(50)',
    'tvc_temp_cold_tol'     => 'VARCHAR(50)',
    'tvc_duration_hot'      => 'VARCHAR(50)',
    'tvc_duration_cold'     => 'VARCHAR(50)',
    'tvc_cycles_required'   => 'VARCHAR(50)',
    'tvc_start_cycle'       => 'VARCHAR(20)',
    'tvc_thermocouples'     => 'VARCHAR(50)',
    'tvc_instructions'      => 'TEXT',
    'tvc_other_tests'       => 'TEXT',
    // --- VCM (Outgassing) Testing Material ---
    'vcm_samples_json'      => 'LONGTEXT',
    'vcm_vacuum_req'        => 'TEXT',
    'vcm_duration'          => 'TEXT',
    'vcm_samples_loaded'    => 'TEXT',
    'vcm_temp_hot_bar'      => 'TEXT',
    'vcm_temp_cold_bar'     => 'TEXT',
    'msld_samples_json'     => 'LONGTEXT',
    'gauge_calibration_json' => 'LONGTEXT',
    'corona_test_json'      => 'LONGTEXT',
    'other_special_test_desc' => 'TEXT',
    'bombing_leak_test_json' => 'LONGTEXT',
    'bombing_staff_json'     => 'LONGTEXT',
    'per_test_risk_json'     => 'LONGTEXT',
    'risk_assessed_uhv'     => 'VARCHAR(50)',
    'rpn_uhv'               => 'VARCHAR(50)',
    'test_object_accepted'  => 'VARCHAR(50)',
    'test_received_reviewed' => 'VARCHAR(50)',
    'test_accepted_by'      => 'TEXT',
    'risk_record_uhv'       => "VARCHAR(20) DEFAULT ''",
    'staff_review_date'     => 'DATETIME',
    'test_code'             => 'VARCHAR(100)',
    'vcm_temp_hot_bar_tol'  => 'TEXT',
    'vcm_temp_cold_bar_tol' => 'TEXT',
    'test_started_datetime'   => 'DATETIME',
    'test_completed_datetime' => 'DATETIME',
    'test_duration'           => 'TEXT',
    'test_on_time'            => 'VARCHAR(50)',
    'specimen_collected_by_name'  => 'TEXT',
    'specimen_collected_by_sig'   => 'TEXT',
    'verification_closed_by_name' => 'TEXT',
    'verification_closed_by_sig'  => 'TEXT',
    'completion_date'         => 'DATETIME',
    'draft_saved_at'          => 'DATETIME',
    'draft_saved_by'          => 'VARCHAR(100)',
];
if (is_array($existing_cols)) {
    // 1. DROP legacy columns immediately to make room
    $drop_cols = [
        'chamber_used',
        'pt_name_of_object','pt_frequency_band','pt_quantity','pt_pressure_range','pt_special_requirements','pt_key_characteristics',
        'bk_vacuum_level','bk_temperature','bk_dwell_time','bk_profile_attached','bk_profile_file','bk_monitor_base_plate','bk_monitor_test_specimen','bk_date_of_test_requirement','bk_special_requirements','bk_key_characteristics',
        'requisition_received_date','risk_form_filled','special_processes',
        'env_vacuum','env_shroud_temp','env_solar_beam','env_eclipse','env_motion_tilt','env_tilt_rate','env_tilt_position','env_motion_spin','env_spin_rate','env_spin_position','env_motion_speed','env_mechanical','env_special_req','env_key_char',
        'env_vacuum_comment','env_shroud_comment','env_solar_comment','env_eclipse_comment','env_motion_comment','env_mechanical_comment','env_special_comment','env_key_comment',
        'risk_assessed','rpn','risk_record','test_type_etf'
    ];
    $to_drop = [];
    foreach ($drop_cols as $dc) if (in_array($dc, $existing_cols)) $to_drop[] = "DROP COLUMN $dc";
    if (!empty($to_drop)) {
        $wpdb->query("ALTER TABLE {$table} " . implode(', ', $to_drop));
        // Refresh existing columns list after dropping to ensure any dropped-but-needed columns are recreated
        $existing_cols = $wpdb->get_col("DESCRIBE {$table}", 0);
    }

    // Migration: Remove project_program column if it exists
    $check_col = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'project_program'");
    if (!empty($check_col)) {
        $wpdb->query("ALTER TABLE $table DROP COLUMN project_program");
    }

    // 2. Convert MANY existing VARCHAR to TEXT to further reduce row size
    // We target common bloat columns from the original schema
    $bloat_cols = [
        'satellite_name','sub_name','sub_email','sub_section','sub_division','sub_designation',
        'rf_connector_type','reviewed_by','test_accepted_by','risk_form_file',
        'test_duration','specimen_collected_by_name','specimen_collected_by_sig','verification_closed_by_name',
        'verification_closed_by_sig','test_type_etf','test_types'
    ];
    $to_modify = [];
    $to_modify[] = "ROW_FORMAT=DYNAMIC";
    foreach ($bloat_cols as $bc) {
        if (in_array($bc, $existing_cols)) {
            $to_modify[] = "MODIFY COLUMN $bc TEXT";
        }
    }
    $wpdb->query("ALTER TABLE {$table} " . implode(', ', $to_modify));

    // 3. ADD new columns if they don't exist
    foreach ($new_cols as $col => $definition) {
        if (!in_array($col, $existing_cols)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$col} {$definition}");
        }
    }
    
    if (in_array('status', $existing_cols)) {
        $wpdb->query("ALTER TABLE {$table} MODIFY COLUMN status VARCHAR(50) DEFAULT 'draft_indenter'");
    }

    // RETROACTIVE FIX: Convert existing PENDING TR numbers to REQXXXXX (approved plan)
    // Drafts should keep their DRAFT- prefix for clarity
    $wpdb->query("UPDATE {$table} SET test_requisition_no = CONCAT('REQ', LPAD(id, 5, '0')) WHERE test_requisition_no LIKE 'PENDING-%'");
}

// ========== HELPERS ==========

// NOTE: fetch_employee AJAX handler is registered in functions.php

function uhv_notify_qa($wpdb, $tr_no, $name, $qa_stno = '') {
    // Notify ONLY the specifically nominated QA employee by staff number
    if (!empty($qa_stno)) {
        $qa_person = $wpdb->get_row($wpdb->prepare(
            "SELECT name, email FROM {$wpdb->prefix}employee_table_2 WHERE stno = %s AND email IS NOT NULL AND email != '' LIMIT 1",
            $qa_stno
        ));
        if ($qa_person && !empty($qa_person->email)) {
            $subject = "UHV: QA Review Required – $tr_no";
            $body    = "Dear {$qa_person->name},\n\nYou have been nominated as QA/T&E Engineer for UHV Test Request: $tr_no\nSubmitted by: $name\n\nPlease log in to review and accept or reject this request.";
            // wp_mail($qa_person->email, $subject, $body);
        }
    }
}
function uhv_notify_managers($wpdb, $tr_no, $name) {
    $mgrs = $wpdb->get_results("SELECT DISTINCT email FROM {$wpdb->prefix}employee_table_2 WHERE funcdesg LIKE '%MANAGER%' AND email IS NOT NULL AND email!=''");
    // foreach ($mgrs as $m) wp_mail($m->email, "New UHV Request - $tr_no", "Submitted by $name\nTR No: $tr_no");
}
function uhv_notify_user($form) {
    if (!$form) return;
    $user_id = $form->user_id;
    if (!$user_id) return;
    $u = get_userdata($user_id);
    if (!$u || empty($u->user_email)) return;
    
    $status_label = str_replace('_', ' ', $form->status);
    $subject = "UHV Test Request Update: {$form->test_requisition_no}";
    $body = "Dear User,\n\nThe status of your UHV Test Request ({$form->test_requisition_no}) has been updated.\n\nCurrent Status: " . strtoupper($status_label) . "\n\nPlease login to the ISITE portal to view details.";
    // wp_mail($u->user_email, $subject, $body);
}
function uhv_notify_uhv($wpdb, $form) {
    $UHV = $wpdb->get_results("SELECT DISTINCT email FROM {$wpdb->prefix}employee_table_2 WHERE sectionfullname LIKE '%LARGE SPACE%' AND email IS NOT NULL AND email!=''");
    // foreach ($UHV as $e) wp_mail($e->email, "UHV Request Approved - {$form->test_requisition_no}", "TR: {$form->test_requisition_no}");
}


function uhv_get_selected_test_labels($form) {
    if (!$form) return [];
    $labels = [];
    $raw = !empty($form->test_types) ? $form->test_types : ($form->test_type ?? '');
    if (!empty($raw)) {
        // Use a more robust split that handles potential ampersand encoding issues
        $parts = preg_split('/\s*,\s*/', (string)$raw);
        foreach ($parts as $part) {
            $part = trim(htmlspecialchars_decode((string)$part, ENT_QUOTES));
            if ($part !== '') $labels[] = $part;
        }
    }
    if (empty($labels) && !empty($form->test_type)) {
        $labels[] = trim(htmlspecialchars_decode((string)$form->test_type, ENT_QUOTES));
    }
    return array_values(array_unique(array_filter($labels)));
}

function uhv_get_per_test_risk($form) {
    if (empty($form->per_test_risk_json)) {
        $map = [];
        foreach (uhv_get_selected_test_labels($form) as $label) {
            $map[$label] = ['test_object_accepted'=>'','risk_assessed_uhv'=>'','rpn_uhv'=>'','risk_record_uhv'=>''];
        }
        return $map;
    }
    
    $data = json_decode($form->per_test_risk_json, true) ?: [];
    // Convert indexed array to label-keyed map if needed
    if (isset($data[0]) && is_array($data[0]) && isset($data[0]['test_label'])) {
        $map = [];
        foreach ($data as $item) {
            $label = $item['test_label'];
            $map[$label] = $item;
        }
        return $map;
    }
    return $data; // Already associative or empty
}

function uhv_render_per_test_risk_readonly($form, $title = 'Per-Test Risk Assessment') {
    $risk_map = uhv_get_per_test_risk($form);
    if (empty($risk_map)) return;
    echo '<div style="margin-top:18px;"><h4 style="margin:0 0 10px;">' . esc_html($title) . '</h4>';
    
    foreach ($risk_map as $test_label => $risk) {
        $rpn = $risk['rpn_uhv'] ?? '';
        $rpn_label = $rpn === 'lt4' ? '&le; 4' : ($rpn === 'gte5' ? '&ge; 5' : '&mdash;');
        echo '<table style="width:100%; border-collapse:collapse; margin-bottom:20px; font-size:14px; border:1px solid #000;">';
        echo '<tr style="background:#f8f9fa;"><th colspan="3" style="border:1px solid #000; padding:10px; text-align:left; color:#0d6efd; font-weight:700;">Test requisitioned: ' . esc_html($test_label) . '</th></tr>';
        echo '<tr>';
        echo '<td style="border:1px solid #000; padding:10px; width:60%;">Test request received, reviewed and accepted for testing</td>';
        echo '<td colspan="2" style="border:1px solid #000; padding:10px; text-align:center; font-weight:600;">' . esc_html(ucfirst(strtolower($risk['test_object_accepted'] ?? '—'))) . '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<td style="border:1px solid #000; padding:10px;">Risk Assessed as per Online QMS UHV Lab Risk Table<br><span style="font-weight:600;">Result: ' . esc_html(ucfirst(strtolower($risk['risk_assessed_uhv'] ?? '—'))) . '</span></td>';
        echo '<td style="border:1px solid #000; padding:10px;">Risk Priority No.(RPN): <span style="font-weight:600;">' . $rpn_label . '</span>';
        if (!empty($risk['risk_table_url'])) {
            echo '<br><a href="' . esc_url($risk['risk_table_url']) . '" target="_blank" style="color:#0d6efd; text-decoration:underline;">View Risk Table</a>';
        }
        echo '<br><small>(as per online QMS Risk Table to be filled if RPN &ge; 5)</small></td>';
        echo '<td style="border:1px solid #000; padding:10px; text-align:center;">Risk Record:<br><span style="font-weight:600;">' . strtoupper(esc_html($risk['risk_record_uhv'] ?? 'NA')) . '</span></td>';
        echo '</tr>';
        echo '</table>';
    }
    if (!empty($form->staff_review_date)) {
        // Try to get staff name from history
        $staff_name = 'UHV Staff';
        $history = uhv_get_history_data($form->id);
        foreach ($history as $h) {
            if ($h['action_label'] === 'Staff Review Completed') {
                $staff_name = $h['done_by'];
                break;
            }
        }
        echo '<div style="margin-top:10px; padding:10px; background:#f0f7ff; border:1px solid #007bff; border-radius:4px; font-size:14px;">';
        echo '<strong>Reviewed By:</strong> ' . esc_html($staff_name) . ' | <strong>Date:</strong> ' . date('d M Y, h:i A', strtotime($form->staff_review_date));
        echo '</div>';
    }
    echo '</div>';
}

/**
 * Renders Section B & C details (Test Execution & Closure) in read-only mode.
 */
function uhv_render_execution_details_readonly($req) {
    if (!$req || empty($req->test_started_datetime)) return;
    
    echo '<div style="margin-top:25px; border:1px solid #000; border-radius:6px; overflow:hidden;">';
    
    // Section B
    echo '<h3 style="margin:0; padding:12px; background:#f8f9fa; border-bottom:1px solid #000; font-size:17px; font-weight:700; text-transform:uppercase;">SECTION B — TEST EXECUTION DETAILS</h3>';
    echo '<table style="width:100%; border-collapse:collapse; font-size:16px;">';
    echo '<tr><th style="border:1px solid #000; padding:15px; text-align:left; background:#fff; width:45%;">Test Started on</th><td style="border:1px solid #000; padding:15px;">' . (!empty($req->test_started_datetime) ? date('d M Y, h:i A', strtotime($req->test_started_datetime)) : '—') . '</td></tr>';
    echo '<tr><th style="border:1px solid #000; padding:15px; text-align:left; background:#fff;">Test Completed on</th><td style="border:1px solid #000; padding:15px;">' . (!empty($req->test_completed_datetime) ? date('d M Y, h:i A', strtotime($req->test_completed_datetime)) : '—') . '</td></tr>';
    echo '<tr><th style="border:1px solid #000; padding:15px; text-align:left; background:#fff;">Test Duration</th><td style="border:1px solid #000; padding:15px;">' . esc_html($req->test_duration ?? '—') . '</td></tr>';
    echo '<tr><th style="border:1px solid #000; padding:15px; text-align:left; background:#fff;">Test Completed On-Time?</th><td style="border:1px solid #000; padding:15px;">' . esc_html($req->test_on_time ?? '—') . '</td></tr>';
    echo '<tr><th style="border:1px solid #000; padding:15px; text-align:left; background:#fff;">Test Code</th><td style="border:1px solid #000; padding:15px;">' . esc_html($req->test_code ?? '—') . '</td></tr>';
    echo '</table>';
    
    // Section C
    echo '<h3 style="margin:0; padding:12px; background:#f8f9fa; border:1px solid #000; border-left:none; border-right:none; font-size:17px; font-weight:700; text-transform:uppercase;">SECTION C — SPECIMEN COLLECTION &amp; CLOSURE</h3>';
    echo '<table style="width:100%; border-collapse:collapse; font-size:16px;">';
    echo '<tr style="background:#000; color:#fff;"><th colspan="2" style="padding:10px; text-align:left; font-weight:600;">Test Specimen Collected By</th></tr>';
    echo '<tr><th style="border:1px solid #000; padding:15px; text-align:left; background:#fff; width:45%;">Name</th><td style="border:1px solid #000; padding:15px;">' . esc_html($req->specimen_collected_by_name ?? '—') . '</td></tr>';
    echo '<tr><th style="border:1px solid #000; padding:15px; text-align:left; background:#fff;">Signature / Initials</th><td style="border:1px solid #000; padding:15px;">' . esc_html($req->specimen_collected_by_sig ?? '—') . '</td></tr>';
    echo '<tr style="background:#000; color:#fff;"><th colspan="2" style="padding:10px; text-align:left; font-weight:600;">Verification &amp; Requisition Closed By</th></tr>';
    echo '<tr><th style="border:1px solid #000; padding:15px; text-align:left; background:#fff; width:45%;">Name</th><td style="border:1px solid #000; padding:15px;">' . esc_html($req->verification_closed_by_name ?? '—') . '</td></tr>';
    echo '<tr><th style="border:1px solid #000; padding:15px; text-align:left; background:#fff;">Signature / Initials</th><td style="border:1px solid #000; padding:15px;">' . esc_html($req->verification_closed_by_sig ?? '—') . '</td></tr>';
    echo '</table>';
    
    echo '</div>';
}

// ========== HISTORY LOG HELPER — appends one entry to history_log JSON column ==========
function uhv_log_history($wpdb, $form_id, $action_label, $from_status, $to_status, $done_by, $done_by_stno, $done_by_role, $comment = '') {
    $table   = $wpdb->prefix . 'uhv_form';
    $current = $wpdb->get_var($wpdb->prepare("SELECT history_log FROM {$table} WHERE id=%d", $form_id));
    $history = [];
    if (!empty($current)) {
        $decoded = json_decode($current, true);
        if (is_array($decoded)) $history = $decoded;
    }
    $history[] = [
        'action_label' => $action_label,
        'from_status'  => $from_status,
        'to_status'    => $to_status,
        'done_by'      => $done_by,
        'done_by_stno' => $done_by_stno,
        'done_by_role' => $done_by_role,
        'comment'      => $comment,
        'created_at'   => date('Y-m-d H:i:s'),
    ];
    $wpdb->update($table, ['history_log' => json_encode($history)], ['id' => $form_id]);
}

// ========== HISTORY UI HELPERS ==========
function uhv_get_history_data($form_id) {
    global $wpdb, $table;
    $f = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $form_id));
    if (!$f) return [];

    $history = [];
    if (!empty($f->history_log)) {
        $decoded = json_decode($f->history_log, true);
        if (is_array($decoded) && !empty($decoded)) $history = $decoded;
    }

    // Backfill if empty
    if (empty($history)) {
        if (!empty($f->submission_date) && !str_contains($f->test_requisition_no, 'DRAFT')) {
            $history[] = [
                'action_label' => 'Form Submitted',
                'from_status'  => str_contains($f->test_requisition_no, 'PENDING') ? 'draft' : 'pending',
                'to_status'    => $f->status,
                'done_by'      => $f->sub_name ?: 'Unknown',
                'done_by_stno' => $f->sub_stno ?: '',
                'done_by_role' => 'indenter',
                'comment'      => '',
                'created_at'   => $f->submission_date,
            ];
        }
        if (!empty($f->approval_date) && !empty($f->reviewed_by)) {
            $history[] = [
                'action_label' => 'Manager Reviewed',
                'from_status'  => 'pending',
                'to_status'    => $f->status,
                'done_by'      => $f->reviewed_by,
                'done_by_stno' => '',
                'done_by_role' => 'manager',
                'comment'      => $f->manager_comment ?: '',
                'created_at'   => $f->approval_date,
            ];
        }
        usort($history, function($a, $b) { return strtotime($a['created_at']) - strtotime($b['created_at']); });
    }

    foreach ($history as &$h) {
        $h['created_at'] = date('d M Y, h:i A', strtotime($h['created_at']));
    }
    return $history;
}

function uhv_history_button($form_id) {
    $history = uhv_get_history_data($form_id);
    $count   = count($history);
    $json    = json_encode($history, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
    $var_id  = 'uhv_hist_' . intval($form_id);
    $badge   = $count > 0 ? " <span style='background:#fff;color:#000;border-radius:50%;padding:1px 7px;font-size:11px;font-weight:700;margin-left:6px;'>$count</span>" : '';
    echo "<script>window['" . esc_js($var_id) . "'] = {$json};</script>
    <button type='button'
        onclick=\"uhvShowHistory('" . esc_js($var_id) . "')\"
        style='background:#343a40;color:#fff;border:none;padding:12px 24px;cursor:pointer;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.8px;display:inline-flex;align-items:center;gap:4px;'>
        🕐 History{$badge}
    </button>";
}

function uhv_history_modal_html() { ?>
<!-- UHV History Modal -->
<div id="uhv_hist_overlay" onclick="uhvCloseHistory()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:99998;"></div>
<div id="uhv_hist_modal" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(720px,95vw);max-height:82vh;background:#fff;z-index:99999;box-shadow:0 8px 40px rgba(0,0,0,.3);flex-direction:column;border-radius:4px;overflow:hidden;">
  <div style="background:#000;color:#fff;padding:18px 24px;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;">
    <span style="font-weight:700;font-size:15px;text-transform:uppercase;letter-spacing:1px;">🕐 Form History</span>
    <button onclick="uhvCloseHistory()" style="background:none;border:none;color:#fff;font-size:22px;cursor:pointer;line-height:1;padding:0 4px;">&times;</button>
  </div>
  <div id="uhv_hist_body" style="overflow-y:auto;padding:24px;flex:1;"></div>
</div>
<script>
function uhvShowHistory(varId) {
  var data = window[varId] || [];
  var body = document.getElementById('uhv_hist_body');
  var modal = document.getElementById('uhv_hist_modal');
  document.getElementById('uhv_hist_overlay').style.display = 'block';
  modal.style.display = 'flex';

  if (!data || !data.length) {
    body.innerHTML = '<p style="color:#888;text-align:center;padding:30px 0;">No history records found for this form.</p>';
    return;
  }
  var actionColors = {
    'Form Submitted':             {bg:'#e8f5e9',border:'#28a745',icon:'📤',color:'#155724'},
    'QA Accepted':                {bg:'#e8f5e9',border:'#28a745',icon:'✅',color:'#155724'},
    'QA Rejected':                {bg:'#fdecea',border:'#dc3545',icon:'❌',color:'#721c24'},
    'Staff Review Completed':     {bg:'#e8f5e9',border:'#28a745',icon:'📋',color:'#155724'},
    'Staff Sent for Recheck':     {bg:'#fff8e1',border:'#fd7e14',icon:'↩', color:'#7a3e00'},
    'Manager Approved':           {bg:'#e8f5e9',border:'#28a745',icon:'✔️',color:'#155724'},
    'Manager Rejected':           {bg:'#fdecea',border:'#dc3545',icon:'❌',color:'#721c24'},
    'Manager Sent for Recheck':   {bg:'#fff8e1',border:'#fd7e14',icon:'↺', color:'#7a3e00'},
    'Resubmitted':                {bg:'#e3f2fd',border:'#1976d2',icon:'📝',color:'#0d47a1'},
    'Test Completed':             {bg:'#1a1a1a',border:'#000',   icon:'★', color:'#fff'},
    'Draft Saved':                {bg:'#f8f9fa',border:'#6c757d',icon:'💾',color:'#343a40'},
  };
  var html = '<div style="position:relative;padding-left:36px;">';
  html += '<div style="position:absolute;left:16px;top:0;bottom:0;width:2px;background:#e0e0e0;"></div>';
  data.forEach(function(h, i) {
    var cfg = actionColors[h.action_label] || {bg:'#f5f5f5',border:'#999',icon:'ℹ',color:'#333'};
    var isLast = (i === data.length - 1);
    html += '<div style="position:relative;margin-bottom:'+(isLast?'0':'20px')+';padding-bottom:'+(isLast?'0':'20px')+(isLast?'':';border-bottom:1px solid #f0f0f0')+'">';
    html += '<div style="position:absolute;left:-28px;top:4px;width:22px;height:22px;border-radius:50%;background:'+cfg.border+';display:flex;align-items:center;justify-content:center;font-size:11px;color:#fff;font-weight:700;border:2px solid #fff;box-shadow:0 0 0 2px '+cfg.border+';">'+(i+1)+'</div>';
    html += '<div style="background:'+cfg.bg+';border-left:4px solid '+cfg.border+';padding:14px 18px;">';
    html += '<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:6px;">';
    html += '<span style="font-weight:700;font-size:14px;color:'+cfg.color+';">'+cfg.icon+' '+h.action_label+'</span>';
    html += '<span style="font-size:11px;color:'+(cfg.bg==='#1a1a1a'?'#aaa':'#888')+';white-space:nowrap;">'+h.created_at+'</span>';
    html += '</div>';
    html += '<div style="margin-top:8px;font-size:13px;color:'+(cfg.bg==='#1a1a1a'?'#ccc':'#444')+';display:flex;flex-wrap:wrap;gap:12px;">';
    html += '<span><strong>By:</strong> '+h.done_by+(h.done_by_stno?' ('+h.done_by_stno+')':'')+'</span>';
    html += '<span><strong>Role:</strong> '+h.done_by_role.toUpperCase()+'</span>';
    if (h.from_status) html += '<span style="background:rgba(0,0,0,.08);padding:2px 8px;border-radius:3px;font-size:11px;">'+h.from_status.toUpperCase()+' → '+h.to_status.toUpperCase()+'</span>';
    html += '</div>';
    if (h.comment) html += '<div style="margin-top:8px;font-size:13px;color:'+(cfg.bg==='#1a1a1a'?'#bbb':'#555')+';background:rgba(0,0,0,.04);padding:8px 12px;border-left:3px solid #ccc;"><strong>Comment:</strong> '+h.comment+'</div>';
    html += '</div></div>';
  });
  html += '</div>';
  body.innerHTML = html;
}
function uhvCloseHistory() {
  document.getElementById('uhv_hist_overlay').style.display = 'none';
  document.getElementById('uhv_hist_modal').style.display = 'none';
}
document.addEventListener('keydown', function(e){ if(e.key==='Escape') uhvCloseHistory(); });
</script>
<?php }

// RENDERS THE DASHBOARD ACTION BUTTONS (VIEW STAFF / QA REVIEW)
function uhv_dashboard_buttons($emp) {
    global $wpdb;
    $table = $wpdb->prefix . 'uhv_form';
    $my_qa_pending_count = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE qa_stno=%s AND (status='pending_qa' OR (status IN ('pending_manager','pending') AND qa_decision=''))",
        $emp->stno
    ));
    $show_staff = !empty($GLOBALS['can_fill_staff_form']);
    ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
      <?php if ($GLOBALS['user_role'] !== 'manager'): ?>
      <?php if ($show_staff): ?>
      <a href="<?php echo esc_url(add_query_arg('action','view_staff', get_permalink())); ?>"
         class="btn" style="background:#17a2b8;color:#fff;padding:12px 22px;">
        &#128196; VIEW STAFF FORM
      </a>
      <?php endif; ?>
      <a href="<?php echo esc_url(add_query_arg('action','qa_dashboard', get_permalink())); ?>"
         class="btn" style="background:#6f42c1;color:#fff;padding:12px 22px;position:relative;">
        &#10003; QA REVIEW
        <span style="background:<?php echo $my_qa_pending_count > 0 ? '#dc3545' : '#6c757d'; ?>;color:#fff;border-radius:50%;padding:1px 7px;font-size:11px;font-weight:700;margin-left:6px;"><?php echo (int) $my_qa_pending_count; ?></span>
      </a>
      <?php endif; ?>
    </div>
    <?php
}

function uhv_user_stat_cards($cnt_pending, $cnt_qa_pending, $cnt_testing, $cnt_rejected, $cnt_completed) {
    if ((int) $cnt_qa_pending === 0) {
        $cnt_qa_pending = (int) get_transient('mgr_qa_count_' . get_current_user_id());
    }
    $base = get_permalink(); ?>
<div class="stat-grid">
  <a href="<?php echo esc_url(get_permalink()); ?>" class="stat-card sc-pending" style="text-decoration:none;">
    <div class="stat-num"><?php echo $cnt_pending; ?></div><div class="stat-lbl">Pending Approval</div>
  </a>
  <a href="<?php echo esc_url(add_query_arg('action','qa_dashboard',$base)); ?>" class="stat-card" style="border-color:#6f42c1;background:#faf7ff;color:#6f42c1;text-decoration:none;cursor:pointer;">
    <div class="stat-num"><?php echo (int) $cnt_qa_pending; ?></div><div class="stat-lbl">My QA Reviews Pending</div>
  </a>
  <a href="<?php echo esc_url(get_permalink()); ?>" class="stat-card sc-approved" style="text-decoration:none;">
    <div class="stat-num"><?php echo $cnt_testing; ?></div><div class="stat-lbl">In Testing</div>
  </a>
  <a href="<?php echo esc_url(get_permalink()); ?>" class="stat-card" style="border-color:#dc3545;background:#fff8f0;color:#7d3c00;text-decoration:none;">
    <div class="stat-num"><?php echo $cnt_rejected; ?></div><div class="stat-lbl">Rejected / Returned</div>
  </a>
  <a href="<?php echo esc_url(get_permalink()); ?>" class="stat-card" style="border-color:#000;background:#f8f8f8;color:#000;text-decoration:none;">
    <div class="stat-num"><?php echo $cnt_completed; ?></div><div class="stat-lbl">Completed</div>
  </a>
</div>
<?php }

// ========== POST HANDLERS ==========

/* ---------- INDENTER SAVE DRAFT ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_indenter_draft'])) {
    if (!uhv_can_edit_test_request($user_role)) {
        wp_die('Unauthorized');
    }
    if (!wp_verify_nonce($_POST['uhv_nonce'],'uhv_action')) wp_die('Security check failed');

    $draft_id = intval($_POST['draft_id'] ?? 0);

    // Handle bk profile file upload
    $bk_profile_file_url = '';
    if (!empty($_FILES['bk_profile_file']['name'])) {
        if (!function_exists('wp_handle_upload')) require_once ABSPATH . 'wp-admin/includes/file.php';
        $bk_upload = wp_handle_upload($_FILES['bk_profile_file'], ['test_form' => false]);
        if (!empty($bk_upload['url'])) $bk_profile_file_url = $bk_upload['url'];
    } elseif (!empty($_POST['bk_profile_file_existing'])) {
        $bk_profile_file_url = esc_url_raw($_POST['bk_profile_file_existing']);
    }

    $draft_data = [
        'submission_date'        => date('Y-m-d H:i:s'),
        'satellite_name'         => sanitize_text_field($_POST['satellite_name'] ?? ''),
        'test_type'              => sanitize_text_field($_POST['test_type'] ?? ''),
        'test_required_on'       => sanitize_text_field($_POST['test_required_on'] ?? ''),
        'sub_name'               => sanitize_text_field($_POST['sub_name'] ?? ''),
        'sub_stno'               => sanitize_text_field($_POST['sub_stno'] ?? ''),
        'sub_email'              => sanitize_text_field($_POST['sub_email'] ?? ''),
        'sub_section'            => substr(sanitize_text_field($_POST['sub_section'] ?? ''), 0, 100),
        'sub_division'           => substr(sanitize_text_field($_POST['sub_division'] ?? ''), 0, 100),
        'sub_designation'        => sanitize_text_field($_POST['sub_designation'] ?? ''),
        'sub_phone'              => sanitize_text_field($_POST['sub_phone'] ?? ''),
        'thermal_power'          => intval($_POST['thermal_power'] ?? 0),
        'thermal_thermocouples'  => intval($_POST['thermal_thermocouples'] ?? 0),
        'ground_dc_signal'       => intval($_POST['ground_dc_signal'] ?? 0),
        'ground_signal_power'    => intval($_POST['ground_signal_power'] ?? 0),
        'rf_connector_type'           => sanitize_text_field($_POST['rf_connector_type'] ?? ''),
        'rf_connector_channels'       => intval($_POST['rf_connector_channels'] ?? 0),
        'rf_connector_comments'       => sanitize_text_field($_POST['rf_connector_comments'] ?? ''),
        'rf_connectors_json'          => (function() {
            $rf_keys = ['N-type Connector','SMA Connector','2.92 mm Connectors','1553 Connector','Others'];
            $rf_data = [];
            foreach ($rf_keys as $key) {
                $slug = 'rf_' . preg_replace('/[^a-z0-9]/i','_', strtolower($key));
                if (!empty($_POST[$slug . '_checked'])) {
                    $rf_data[] = [
                        'type'     => $key === 'Others' ? sanitize_text_field($_POST['rf_others_type'] ?? 'Others') : $key,
                        'channels' => intval($_POST[$slug . '_channels'] ?? 0),
                        'comments' => sanitize_text_field($_POST[$slug . '_comments'] ?? ''),
                    ];
                }
            }
            return json_encode($rf_data);
        })(),
        'thermal_power_comments'      => sanitize_text_field($_POST['thermal_power_comments'] ?? ''),
        'thermal_thermocouples_comments' => sanitize_text_field($_POST['thermal_thermocouples_comments'] ?? ''),
        'ground_dc_signal_comments'   => sanitize_text_field($_POST['ground_dc_signal_comments'] ?? ''),
        'ground_signal_power_comments'=> sanitize_text_field($_POST['ground_signal_power_comments'] ?? ''),
        'special_requirements'        => sanitize_textarea_field($_POST['special_requirements'] ?? ''),
        'qa_exists'              => sanitize_text_field($_POST['qa_exists'] ?? 'no'),
        'qa_name'                => sanitize_text_field($_POST['qa_name'] ?? ''),
        'qa_stno'                => sanitize_text_field($_POST['qa_stno'] ?? ''),
        'qa_email'               => sanitize_text_field($_POST['qa_email'] ?? ''),
        'qa_designation'         => sanitize_text_field($_POST['qa_designation'] ?? ''),
        'qa_section'             => sanitize_text_field($_POST['qa_section'] ?? ''),
        'qa_phone'               => sanitize_text_field($_POST['qa_phone'] ?? ''),
        'user_id'                => $user->ID,
        'status'                 => 'draft_indenter',
        'indenter_draft_saved_at'=> date('Y-m-d H:i:s'),
        'indenter_draft_saved_by'=> $emp->name,
        // Multi-test type fields
        'test_types'             => sanitize_text_field(implode(', ', array_filter(array_map('sanitize_text_field', (array)($_POST['test_types'] ?? []))))),
        // Multipaction Test fields
        'mp_package_size'       => (function() {
            $size = [
                'l' => sanitize_text_field($_POST['mp_package_l'] ?? ''),
                'b' => sanitize_text_field($_POST['mp_package_b'] ?? ''),
                'h' => sanitize_text_field($_POST['mp_package_h'] ?? ''),
            ];
            return json_encode($size);
        })(),
        'mp_test_profile_attach'=> sanitize_text_field($_POST['mp_test_profile_attach'] ?? ''),
        'mp_test_profile_file'  => (function() {
            if (!empty($_FILES['mp_test_profile_file']['name'])) {
                if (!function_exists('wp_handle_upload')) require_once ABSPATH . 'wp-admin/includes/file.php';
                $upload = wp_handle_upload($_FILES['mp_test_profile_file'], ['test_form' => false]);
                return !empty($upload['url']) ? $upload['url'] : ($_POST['mp_test_profile_file_existing'] ?? '');
            }
            return sanitize_text_field($_POST['mp_test_profile_file_existing'] ?? '');
        })(),
        'mp_thermocouples'      => max(0, intval($_POST['mp_thermocouples'] ?? 0)),
        'mp_ft_rf_qty'          => intval($_POST['mp_ft_rf_qty'] ?? 0),
        'mp_ft_elec_qty'        => intval($_POST['mp_ft_elec_qty'] ?? 0),
        'mp_ft_others_spec'     => sanitize_text_field($_POST['mp_ft_others_spec'] ?? ''),
        'mp_ft_others_qty'      => intval($_POST['mp_ft_others_qty'] ?? 0),
        'mp_special_instructions'=> sanitize_textarea_field($_POST['mp_special_instructions'] ?? ''),
        // TVC fields
        
                'tvc_package_size'      => (function() {
            $size = [
                'l' => sanitize_text_field($_POST['tvc_package_l'] ?? ''),
                'b' => sanitize_text_field($_POST['tvc_package_b'] ?? ''),
                'h' => sanitize_text_field($_POST['tvc_package_h'] ?? ''),
            ];
            return json_encode($size);
        })(),

                'tvc_vacuum_range'      => (function() {
            $vac = [
                'mantissa' => sanitize_text_field($_POST['tvc_vacuum_mantissa'] ?? ''),
                'exponent' => sanitize_text_field($_POST['tvc_vacuum_exponent'] ?? ''),
            ];
            return json_encode($vac);
        })(),

        'tvc_temp_hot'          => sanitize_text_field($_POST['tvc_temp_hot'] ?? ''),
        'tvc_temp_hot_tol'      => sanitize_text_field($_POST['tvc_temp_hot_tol'] ?? ''),
        'tvc_temp_cold'         => sanitize_text_field($_POST['tvc_temp_cold'] ?? ''),
        'tvc_temp_cold_tol'     => sanitize_text_field($_POST['tvc_temp_cold_tol'] ?? ''),
        'tvc_duration_hot'      => sanitize_text_field($_POST['tvc_duration_hot'] ?? ''),
        'tvc_duration_cold'     => sanitize_text_field($_POST['tvc_duration_cold'] ?? ''),
                'tvc_cycles_required'   => intval($_POST['tvc_cycles_required'] ?? 0),

                'tvc_start_cycle'       => sanitize_text_field($_POST['tvc_start_cycle'] ?? 'Hot'),

                'tvc_thermocouples'     => intval($_POST['tvc_thermocouples'] ?? 0),

                'tvc_instructions'      => sanitize_textarea_field($_POST['tvc_instructions'] ?? ''),
        'tvc_other_tests'       => sanitize_textarea_field($_POST['tvc_other_tests'] ?? ''),

        // VCM fields
        'vcm_samples_json'      => (function() {
            $data = [];
            $descs = $_POST['vcm_desc'] ?? [];
            for($i=0; $i<12; $i++) {
                if (!empty($descs[$i])) {
                    $data[] = [
                        'desc'   => sanitize_text_field($descs[$i]),
                        'sample' => sanitize_text_field($_POST['vcm_sample'][$i] ?? ''),
                        'qty'    => sanitize_text_field($_POST['vcm_qty'][$i] ?? ''),
                        'others' => sanitize_text_field($_POST['vcm_others'][$i] ?? ''),
                    ];
                }
            }
            return json_encode($data);
        })(),
                'vcm_vacuum_req'        => (function() {
            $vac = [
                'mantissa' => sanitize_text_field($_POST['vcm_vacuum_mantissa'] ?? ''),
                'exponent' => sanitize_text_field($_POST['vcm_vacuum_exponent'] ?? ''),
            ];
            return json_encode($vac);
        })(),

                'vcm_duration'          => intval($_POST['vcm_duration'] ?? 0),

                'vcm_samples_loaded'    => intval($_POST['vcm_samples_loaded'] ?? 0),

                'vcm_temp_hot_bar'      => uhv_form_sanitize_opt_numeric('vcm_temp_hot_bar'),
        'vcm_temp_hot_bar_tol'  => uhv_form_sanitize_opt_numeric('vcm_temp_hot_bar_tol'),

                'vcm_temp_cold_bar'     => uhv_form_sanitize_opt_numeric('vcm_temp_cold_bar'),
        'vcm_temp_cold_bar_tol' => uhv_form_sanitize_opt_numeric('vcm_temp_cold_bar_tol'),

        'msld_samples_json'      => (function() {
            $data = [];
            $qtys = $_POST['msld_qty'] ?? [];
            $remarks = $_POST['msld_remarks'] ?? [];
            $others_spec = sanitize_text_field($_POST['msld_others_spec'] ?? '');
            $msld_rows = [
                "Heat pipe / HMC cases",
                "Thermal / Electrical / RF / Thermocouple feedthrough",
                "Shrouds / Bellows",
                "Vacuum lines & fittings",
                "Wave guides / Valves / Gauges",
                "Others"
            ];
            foreach($msld_rows as $idx => $desc) {
                $row = [
                    'qty'     => sanitize_text_field($qtys[$idx] ?? ''),
                    'remarks' => sanitize_text_field($remarks[$idx] ?? ''),
                ];
                if ($desc === "Others") {
                    $row['others_spec'] = $others_spec;
                }
                $data[] = $row;
            }
            return json_encode($data);
        })(),
        'gauge_calibration_json' => (function() {
            $gauges = [];
            $makes = $_POST['gauge_make'] ?? [];
            for($i=0; $i<4; $i++) {
                if (!empty($makes[$i])) {
                    $gauges[] = [
                        'make'  => sanitize_text_field($makes[$i]),
                        'model' => sanitize_text_field($_POST['gauge_model'][$i] ?? ''),
                        'slno'  => sanitize_text_field($_POST['gauge_slno'][$i] ?? ''),
                        'range' => sanitize_text_field($_POST['gauge_range'][$i] ?? ''),
                    ];
                }
            }
            $refs = array_map('sanitize_text_field', (array)($_POST['gauge_refs'] ?? []));
            $remarks = $_POST['gauge_refs_remarks'] ?? [];
            $refs_full = [];
            foreach($refs as $rname) {
                $refs_full[$rname] = sanitize_text_field($remarks[$rname] ?? '');
            }
            return json_encode(['gauges' => $gauges, 'refs' => $refs, 'refs_full' => $refs_full]);
        })(),
        'corona_test_json'      => (function() {
            $data = [];
            $descs = $_POST['corona_desc'] ?? [];
            for($i=0; $i<3; $i++) {
                if (!empty($descs[$i])) {
                    $data[] = [
                        'desc'     => sanitize_text_field($descs[$i]),
                        'qty'      => sanitize_text_field($_POST['corona_qty'][$i] ?? ''),
                        'vacuum'   => sanitize_text_field($_POST['corona_vacuum'][$i] ?? ''),
                        'duration' => sanitize_text_field($_POST['corona_duration'][$i] ?? ''),
                        'remarks'  => sanitize_text_field($_POST['corona_remarks'][$i] ?? ''),
                    ];
                }
            }
            return json_encode($data);
        })(),
        'other_special_test_desc' => sanitize_textarea_field($_POST['other_special_test_desc'] ?? ''),
        'bombing_leak_test_json' => (function() {
            $data = [];
            $names = $_POST['bombing_name'] ?? [];
            for($i=0; $i<5; $i++) {
                if (!empty($names[$i])) {
                    $data[] = [
                        'name'     => sanitize_text_field($names[$i]),
                        'qty'      => sanitize_text_field($_POST['bombing_qty'][$i] ?? ''),
                        'pressure' => sanitize_text_field($_POST['bombing_pressure'][$i] ?? ''),
                        'dwell'    => sanitize_text_field($_POST['bombing_dwell'][$i] ?? ''),
                        'rate'     => sanitize_text_field($_POST['bombing_rate'][$i] ?? ''),
                    ];
                }
            }
            return json_encode($data);
        })(),
    ];

    $draft_write_ok = false;
    if ($draft_id > 0) {
        // Fetch the existing record's status before updating — we must not overwrite
        // qa_rejected / rejected / recheck_indenter status back to draft_indenter.
        $existing_status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$table} WHERE id=%d AND user_id=%d LIMIT 1",
            $draft_id, $user->ID
        ));
        // Only allow editing records that are in an editable state
        $editable_statuses = ['draft_indenter', 'qa_rejected', 'rejected', 'recheck_indenter'];
        if ($existing_status && in_array($existing_status, $editable_statuses)) {
            // Preserve the original status — don't reset qa_rejected / recheck_indenter to draft_indenter
            if (in_array($existing_status, ['qa_rejected', 'rejected', 'recheck_indenter'])) {
                $draft_data['status'] = $existing_status;
            }
            $result = $wpdb->update($table, $draft_data, ['id' => $draft_id, 'user_id' => $user->ID]);
            if ($result !== false) { $draft_write_ok = true; }
            else { error_log('UHV draft update error: ' . $wpdb->last_error); }
        } else {
            // Not found or not editable — treat as failed update
            error_log('UHV draft update skipped: id=' . $draft_id . ' existing_status=' . $existing_status);
        }
        $saved_draft_id = $draft_id;
    } else {
        $tr_placeholder = 'DRAFT-' . $user->ID . '-' . uniqid();
        $insert_data = array_merge(['test_requisition_no' => $tr_placeholder], $draft_data);
        $result = $wpdb->insert($table, $insert_data);
        if ($result !== false) {
            $draft_write_ok = true;
            $saved_draft_id = $wpdb->insert_id;
        } else {
            error_log('UHV draft insert error: ' . $wpdb->last_error);
            $saved_draft_id = 0;
        }
    }
    if (!$draft_write_ok) {
        set_transient('uhv_errors_' . $user->ID, ['Draft could not be saved. Database error: ' . $wpdb->last_error], 60);
        $fail_redirect = ($user_role === 'manager')
            ? add_query_arg(['mgr_action' => 'create_new', 'uhv_msg' => 'error'], get_permalink())
            : (($user_role === 'UHV')
                ? add_query_arg(['action' => 'create_new', 'uhv_msg' => 'error'], get_permalink())
                : add_query_arg('uhv_msg', 'error', get_permalink()));
        wp_redirect($fail_redirect);
        exit;
    }

    // --- History Log ---
    if ($saved_draft_id) {
        uhv_log_history($wpdb, $saved_draft_id, 'Draft Saved', 'none', 'draft_indenter', $emp->name, $emp->stno, $user_role, '');
    }

    // Standardize TR numbering for submitted requests; Drafts are handled separately
    $cur_tr = $wpdb->get_var($wpdb->prepare("SELECT test_requisition_no FROM {$table} WHERE id=%d", $saved_draft_id));
    if ($cur_tr && preg_match('/^PENDING-/', $cur_tr)) {
        $tr_no = 'REQ' . str_pad($saved_draft_id, 5, '0', STR_PAD_LEFT);
        $wpdb->update($table, ['test_requisition_no' => $tr_no], ['id' => $saved_draft_id]);
    }
    // Redirect — manager returns to create_new; for qa_rejected/recheck_indenter records,
    // go back to create_new with resume_draft so the context banner is visible.
    $saved_final_status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$table} WHERE id=%d", $saved_draft_id));
    if ($user_role === 'manager') {
        wp_redirect(add_query_arg(['mgr_action' => 'create_new', 'resume_draft' => $saved_draft_id, 'uhv_msg' => 'draft_saved'], remove_query_arg(['action','view_id','complete_id','prog_id'], get_permalink())));
    } elseif (in_array($saved_final_status, ['qa_rejected', 'rejected', 'recheck_indenter'], true)) {
        // Stay in the edit form so the context banner (QA remarks / manager comment) is still visible
        wp_redirect(add_query_arg(['action' => 'create_new', 'resume_draft' => $saved_draft_id, 'uhv_msg' => 'draft_saved'], remove_query_arg(['view_id','mgr_action','complete_id','prog_id'], get_permalink())));
    } elseif ($user_role === 'UHV') {
        // UHV default dashboard is staff lists; keep user on the TR form after save
        wp_redirect(add_query_arg(['action' => 'create_new', 'resume_draft' => $saved_draft_id, 'uhv_msg' => 'draft_saved'], remove_query_arg(['view_id','resume_draft','mgr_action','complete_id','prog_id'], get_permalink())));
    } else {
        wp_redirect(add_query_arg(['uhv_msg' => 'draft_saved', 'resume_draft' => $saved_draft_id], remove_query_arg(['action','view_id','resume_draft','mgr_action','complete_id','prog_id'], get_permalink())));
    }
    exit;
}

/* ---------- INDENTER/MANAGER SUBMIT FOR APPROVAL ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['submit_request'])) {
    if (!uhv_can_edit_test_request($user_role)) {
        wp_die('Unauthorized');
    }
    if (!wp_verify_nonce($_POST['uhv_nonce'],'uhv_action')) wp_die('Security check failed');

    $form_id = intval($_POST['draft_id'] ?? 0);
    $is_resubmit = ($form_id > 0);
    $qa_required = strtolower(sanitize_text_field($_POST['qa_exists'] ?? 'no'));
    // Overhaul: If QA is not selected, it goes to Staff Review first (pending_staff)
    $submission_status = ($qa_required === 'yes') ? 'pending_qa' : 'pending_staff';
    if (!empty($_FILES['bk_profile_file']['name'])) {
        if (!function_exists('wp_handle_upload')) require_once ABSPATH . 'wp-admin/includes/file.php';
        $bk_upload = wp_handle_upload($_FILES['bk_profile_file'], ['test_form' => false]);
        if (!empty($bk_upload['url'])) $bk_profile_file_url = $bk_upload['url'];
    } elseif (!empty($_POST['bk_profile_file_existing'])) {
        $bk_profile_file_url = esc_url_raw($_POST['bk_profile_file_existing']);
    }

    $errors = [];
    if (empty($_POST['satellite_name'])) $errors[]='Test Object name required';
    $submitted_types = array_filter(array_map('sanitize_text_field', (array)($_POST['test_types'] ?? [])));
    if (empty($submitted_types) && empty($_POST['test_type'])) $errors[]='Please select at least one Type of Test';
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
            $submission_status = ($qa_required === 'yes') ? 'pending_qa' : 'pending_staff';
            
            $manager_submit_data = [
                'test_requisition_no'    => 'PENDING-' . $user->ID . '-' . uniqid(),
                'submission_date'        => date('Y-m-d H:i:s'),
                'satellite_name'         => sanitize_text_field($_POST['satellite_name']),
                'test_type'              => sanitize_text_field($_POST['test_type']),
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
                'rf_connectors_json'     => (function() {
                    $rf_keys = ['N-type Connector','SMA Connector','2.92 mm Connectors','1553 Connector','Others'];
                    $rf_data = [];
                    foreach ($rf_keys as $key) {
                        $slug = 'rf_' . preg_replace('/[^a-z0-9]/i','_', strtolower($key));
                        if (!empty($_POST[$slug . '_checked'])) {
                            $rf_data[] = [
                                'type'     => $key === 'Others' ? sanitize_text_field($_POST['rf_others_type'] ?? 'Others') : $key,
                                'channels' => intval($_POST[$slug . '_channels'] ?? 0),
                                'comments' => sanitize_text_field($_POST[$slug . '_comments'] ?? ''),
                            ];
                        }
                    }
                    return json_encode($rf_data);
                })(),
                'thermal_power_comments' => sanitize_text_field($_POST['thermal_power_comments'] ?? ''),
                'thermal_thermocouples_comments' => sanitize_text_field($_POST['thermal_thermocouples_comments'] ?? ''),
                'ground_dc_signal_comments' => sanitize_text_field($_POST['ground_dc_signal_comments'] ?? ''),
                'ground_signal_power_comments' => sanitize_text_field($_POST['ground_signal_power_comments'] ?? ''),
                'gauge_calibration_json' => (function() {
                    $gauges = [];
                    $makes = $_POST['gauge_make'] ?? [];
                    for($i=0; $i<4; $i++) {
                        if (!empty($makes[$i])) {
                            $gauges[] = [
                                'make'  => sanitize_text_field($makes[$i]),
                                'model' => sanitize_text_field($_POST['gauge_model'][$i] ?? ''),
                                'slno'  => sanitize_text_field($_POST['gauge_slno'][$i] ?? ''),
                                'range' => sanitize_text_field($_POST['gauge_range'][$i] ?? ''),
                            ];
                        }
                    }
                    $refs = array_map('sanitize_text_field', (array)($_POST['gauge_refs'] ?? []));
                    $remarks = $_POST['gauge_refs_remarks'] ?? [];
                    $refs_full = [];
                    foreach($refs as $rname) {
                        $refs_full[$rname] = sanitize_text_field($remarks[$rname] ?? '');
                    }
                    return json_encode(['gauges' => $gauges, 'refs' => $refs, 'refs_full' => $refs_full]);
                })(),
                'corona_test_json'      => (function() {
                    $data = [];
                    $descs = $_POST['corona_desc'] ?? [];
                    for($i=0; $i<3; $i++) {
                        if (!empty($descs[$i])) {
                            $data[] = [
                                'desc'     => sanitize_text_field($descs[$i]),
                                'qty'      => sanitize_text_field($_POST['corona_qty'][$i] ?? ''),
                                'vacuum'   => sanitize_text_field($_POST['corona_vacuum'][$i] ?? ''),
                                'duration' => sanitize_text_field($_POST['corona_duration'][$i] ?? ''),
                                'remarks'  => sanitize_text_field($_POST['corona_remarks'][$i] ?? ''),
                            ];
                        }
                    }
                    return json_encode($data);
                })(),
                'other_special_test_desc' => sanitize_textarea_field($_POST['other_special_test_desc'] ?? ''),
                'bombing_leak_test_json' => (function() {
                    $data = [];
                    $names = $_POST['bombing_name'] ?? [];
                    for($i=0; $i<5; $i++) {
                        if (!empty($names[$i])) {
                            $data[] = [
                                'name'     => sanitize_text_field($names[$i]),
                                'qty'      => sanitize_text_field($_POST['bombing_qty'][$i] ?? ''),
                                'pressure' => sanitize_text_field($_POST['bombing_pressure'][$i] ?? ''),
                                'dwell'    => sanitize_text_field($_POST['bombing_dwell'][$i] ?? ''),
                                'rate'     => sanitize_text_field($_POST['bombing_rate'][$i] ?? ''),
                            ];
                        }
                    }
                    return json_encode($data);
                })(),
                'special_requirements'   => sanitize_textarea_field($_POST['special_requirements'] ?? ''),
                'qa_exists'              => $qa_required,
                'qa_name'                => sanitize_text_field($_POST['qa_name'] ?? ''),
                'qa_stno'                => sanitize_text_field($_POST['qa_stno'] ?? ''),
                'qa_email'               => sanitize_text_field($_POST['qa_email'] ?? ''),
                'qa_designation'         => sanitize_text_field($_POST['qa_designation'] ?? ''),
                'qa_section'             => sanitize_text_field($_POST['qa_section'] ?? ''),
                'qa_phone'               => sanitize_text_field($_POST['qa_phone'] ?? ''),
                'user_id'                => $user->ID,
                'status'                 => $submission_status,
        // Multi-test type & sub-form fields
        'test_types'             => sanitize_text_field(implode(', ', array_filter(array_map('sanitize_text_field', (array)($_POST['test_types'] ?? []))))),
        // Multipaction Test fields
        'mp_package_size'       => (function() {
            $size = [
                'l' => sanitize_text_field($_POST['mp_package_l'] ?? ''),
                'b' => sanitize_text_field($_POST['mp_package_b'] ?? ''),
                'h' => sanitize_text_field($_POST['mp_package_h'] ?? ''),
            ];
            return json_encode($size);
        })(),
        'mp_test_profile_attach'=> sanitize_text_field($_POST['mp_test_profile_attach'] ?? ''),
        'mp_test_profile_file'  => (function() {
            if (!empty($_FILES['mp_test_profile_file']['name'])) {
                if (!function_exists('wp_handle_upload')) require_once ABSPATH . 'wp-admin/includes/file.php';
                $upload = wp_handle_upload($_FILES['mp_test_profile_file'], ['test_form' => false]);
                return !empty($upload['url']) ? $upload['url'] : ($_POST['mp_test_profile_file_existing'] ?? '');
            }
            return sanitize_text_field($_POST['mp_test_profile_file_existing'] ?? '');
        })(),
        'mp_thermocouples'      => max(0, intval($_POST['mp_thermocouples'] ?? 0)),
        'mp_ft_rf_qty'          => intval($_POST['mp_ft_rf_qty'] ?? 0),
        'mp_ft_elec_qty'        => intval($_POST['mp_ft_elec_qty'] ?? 0),
        'mp_ft_others_spec'     => sanitize_text_field($_POST['mp_ft_others_spec'] ?? ''),
        'mp_ft_others_qty'      => intval($_POST['mp_ft_others_qty'] ?? 0),
        'mp_special_instructions'=> sanitize_textarea_field($_POST['mp_special_instructions'] ?? ''),
        // TVC fields
                'tvc_package_size'      => (function() {
            $size = [
                'l' => sanitize_text_field($_POST['tvc_package_l'] ?? ''),
                'b' => sanitize_text_field($_POST['tvc_package_b'] ?? ''),
                'h' => sanitize_text_field($_POST['tvc_package_h'] ?? ''),
            ];
            return json_encode($size);
        })(),

                'tvc_vacuum_range'      => (function() {
            $vac = [
                'mantissa' => sanitize_text_field($_POST['tvc_vacuum_mantissa'] ?? ''),
                'exponent' => sanitize_text_field($_POST['tvc_vacuum_exponent'] ?? ''),
            ];
            return json_encode($vac);
        })(),

        
        'tvc_temp_hot'          => sanitize_text_field($_POST['tvc_temp_hot'] ?? ''),
        'tvc_temp_hot_tol'      => sanitize_text_field($_POST['tvc_temp_hot_tol'] ?? ''),
        'tvc_temp_cold'         => sanitize_text_field($_POST['tvc_temp_cold'] ?? ''),
        'tvc_temp_cold_tol'     => sanitize_text_field($_POST['tvc_temp_cold_tol'] ?? ''),
        'tvc_duration_hot'      => sanitize_text_field($_POST['tvc_duration_hot'] ?? ''),
        'tvc_duration_cold'     => sanitize_text_field($_POST['tvc_duration_cold'] ?? ''),
                'tvc_cycles_required'   => intval($_POST['tvc_cycles_required'] ?? 0),

        'tvc_start_cycle'       => sanitize_text_field($_POST['tvc_start_cycle'] ?? ''),
                'tvc_thermocouples'     => intval($_POST['tvc_thermocouples'] ?? 0),

                'tvc_instructions'      => sanitize_textarea_field($_POST['tvc_instructions'] ?? ''),
        'tvc_other_tests'       => sanitize_textarea_field($_POST['tvc_other_tests'] ?? ''),

        // VCM fields
        'vcm_samples_json'      => (function() {
            $data = [];
            $descs = $_POST['vcm_desc'] ?? [];
            for($i=0; $i<12; $i++) {
                if (!empty($descs[$i])) {
                    $data[] = [
                        'desc'   => sanitize_text_field($descs[$i]),
                        'sample' => sanitize_text_field($_POST['vcm_sample'][$i] ?? ''),
                        'qty'    => sanitize_text_field($_POST['vcm_qty'][$i] ?? ''),
                        'others' => sanitize_text_field($_POST['vcm_others'][$i] ?? ''),
                    ];
                }
            }
            return json_encode($data);
        })(),
                'vcm_vacuum_req'        => (function() {
            $vac = [
                'mantissa' => sanitize_text_field($_POST['vcm_vacuum_mantissa'] ?? ''),
                'exponent' => sanitize_text_field($_POST['vcm_vacuum_exponent'] ?? ''),
            ];
            return json_encode($vac);
        })(),

                'vcm_duration'          => intval($_POST['vcm_duration'] ?? 0),

                'vcm_samples_loaded'    => intval($_POST['vcm_samples_loaded'] ?? 0),

                'vcm_temp_hot_bar'      => uhv_form_sanitize_opt_numeric('vcm_temp_hot_bar'),
        'vcm_temp_hot_bar_tol'  => uhv_form_sanitize_opt_numeric('vcm_temp_hot_bar_tol'),

                'vcm_temp_cold_bar'     => uhv_form_sanitize_opt_numeric('vcm_temp_cold_bar'),
        'vcm_temp_cold_bar_tol' => uhv_form_sanitize_opt_numeric('vcm_temp_cold_bar_tol'),

        'msld_samples_json'      => (function() {
            $data = [];
            $qtys = $_POST['msld_qty'] ?? [];
            $remarks = $_POST['msld_remarks'] ?? [];
            $others_spec = sanitize_text_field($_POST['msld_others_spec'] ?? '');
            $msld_rows = [
                "Heat pipe / HMC cases",
                "Thermal / Electrical / RF / Thermocouple feedthrough",
                "Shrouds / Bellows",
                "Vacuum lines & fittings",
                "Wave guides / Valves / Gauges",
                "Others"
            ];
            foreach($msld_rows as $idx => $desc) {
                $row = [
                    'qty'     => sanitize_text_field($qtys[$idx] ?? ''),
                    'remarks' => sanitize_text_field($remarks[$idx] ?? ''),
                ];
                if ($desc === "Others") {
                    $row['others_spec'] = $others_spec;
                }
                $data[] = $row;
            }
            return json_encode($data);
        })(),
            ];
            
            // Bug Fix #4: Check if there's an existing draft to UPDATE before INSERTing
            $mgr_draft_id = intval($_POST['draft_id'] ?? 0);
            $mgr_operation_ok = false;
            $mgr_final_id = 0;
            
            if ($mgr_draft_id > 0) {
                // Check the draft belongs to this manager and is still a draft
                $mgr_existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, status FROM {$table} WHERE id=%d AND user_id=%d AND status='draft_indenter' LIMIT 1",
                    $mgr_draft_id, $user->ID
                ));
                if ($mgr_existing) {
                    // UPDATE the existing draft instead of inserting a new record
                    $update_data = $manager_submit_data;
                    unset($update_data['test_requisition_no']); // Don't overwrite TR no on update
                    $update_data['status'] = $submission_status;
                    $update_res = $wpdb->update($table, $update_data, ['id' => $mgr_draft_id]);
                    if ($update_res !== false) {
                        $mgr_operation_ok = true;
                        $mgr_final_id = $mgr_draft_id;
                        error_log('✓ MANAGER SUBMIT → UPDATE DRAFT: id=' . $mgr_draft_id . ' | status=' . $submission_status);
                    } else {
                        error_log('✗ MANAGER SUBMIT → UPDATE FAILED: ' . $wpdb->last_error);
                    }
                }
            }
            
            if (!$mgr_operation_ok) {
                // No valid draft found — create a fresh record
                error_log('MANAGER → ATTEMPTING INSERT: tr_no=' . $manager_submit_data['test_requisition_no'] . ' | status=' . $submission_status);
                $insert_result = $wpdb->insert($table, $manager_submit_data);
                if ($insert_result !== false) {
                    $mgr_operation_ok = true;
                    $mgr_final_id = $wpdb->insert_id;
                    error_log('✓ MANAGER SUBMIT → INSERT SUCCESS: id=' . $mgr_final_id . ' | user=' . $user->ID);
                } else {
                    error_log('✗ MANAGER SUBMIT → INSERT FAILED: ' . $wpdb->last_error);
                }
            }
            
            if ($mgr_operation_ok) {
                $inserted_id = $mgr_final_id;
                
                // Standardize TR numbering
                $tr_no = 'REQ' . str_pad($inserted_id, 5, '0', STR_PAD_LEFT);
                $wpdb->update($table, ['test_requisition_no' => $tr_no], ['id' => $inserted_id]);
                
                uhv_log_history($wpdb, $inserted_id, 'Form Submitted', 'draft_indenter', $submission_status, $emp->name, $emp->stno, 'manager', 'Manager-initiated submission');

                // Send notifications based on status
                if ($submission_status === 'pending_qa') {
                    uhv_notify_qa($wpdb, $tr_no, $emp->name, $manager_submit_data['qa_stno'] ?? '');
                } else {
                    uhv_notify_managers($wpdb, $tr_no, $emp->name);
                }
                
                wp_redirect(add_query_arg('uhv_msg', 'submitted', remove_query_arg(['action','view_id','resume_draft','mgr_action','complete_id','prog_id'], get_permalink())));
                exit;
            } else {
                // All operations failed — log and show error
                $db_error = $wpdb->last_error;
                error_log('✗ MANAGER SUBMIT → ALL OPS FAILED: error=' . $db_error . ' | user=' . $user->ID);
                $errors[] = 'Failed to create submission. Database error: ' . $db_error;
            }
        }
        // ════════════════════════════════════════════════════════════════════
        // USER SUBMISSION (indenter / external tr_submitter / UHV staff) — UPDATE or INSERT draft
        // ════════════════════════════════════════════════════════════════════
        else if (in_array($user_role, ['indenter', 'tr_submitter', 'UHV'], true)) {
            $draft_id = intval($_POST['draft_id'] ?? 0);
            
            $qa_required = sanitize_text_field($_POST['qa_exists'] ?? 'no');
            if (!in_array($qa_required, ['yes', 'no'])) {
                $qa_required = 'no';
            }
            $submission_status = ($qa_required === 'yes') ? 'pending_qa' : 'pending_staff';
            
            $submit_data = [
                'submission_date'        => date('Y-m-d H:i:s'),
                'satellite_name'         => sanitize_text_field($_POST['satellite_name']),
                'test_type'              => sanitize_text_field($_POST['test_type']),
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
                'rf_connectors_json'     => (function() {
                    $rf_keys = ['N-type Connector','SMA Connector','2.92 mm Connectors','1553 Connector','Others'];
                    $rf_data = [];
                    foreach ($rf_keys as $key) {
                        $slug = 'rf_' . preg_replace('/[^a-z0-9]/i','_', strtolower($key));
                        if (!empty($_POST[$slug . '_checked'])) {
                            $rf_data[] = [
                                'type'     => $key === 'Others' ? sanitize_text_field($_POST['rf_others_type'] ?? 'Others') : $key,
                                'channels' => intval($_POST[$slug . '_channels'] ?? 0),
                                'comments' => sanitize_text_field($_POST[$slug . '_comments'] ?? ''),
                            ];
                        }
                    }
                    return json_encode($rf_data);
                })(),
                'thermal_power_comments' => sanitize_text_field($_POST['thermal_power_comments'] ?? ''),
                'thermal_thermocouples_comments' => sanitize_text_field($_POST['thermal_thermocouples_comments'] ?? ''),
                'ground_dc_signal_comments' => sanitize_text_field($_POST['ground_dc_signal_comments'] ?? ''),
                'ground_signal_power_comments' => sanitize_text_field($_POST['ground_signal_power_comments'] ?? ''),
                'special_requirements'   => sanitize_textarea_field($_POST['special_requirements'] ?? ''),
                'qa_exists'              => $qa_required,
                'qa_name'                => sanitize_text_field($_POST['qa_name'] ?? ''),
                'qa_stno'                => sanitize_text_field($_POST['qa_stno'] ?? ''),
                'qa_email'               => sanitize_text_field($_POST['qa_email'] ?? ''),
                'qa_designation'         => sanitize_text_field($_POST['qa_designation'] ?? ''),
                'qa_section'             => sanitize_text_field($_POST['qa_section'] ?? ''),
                'qa_phone'               => sanitize_text_field($_POST['qa_phone'] ?? ''),
                'user_id'                => $user->ID,
                'status'                 => $submission_status,
        // Multi-test type & sub-form fields
        'test_types'             => sanitize_text_field(implode(', ', array_filter(array_map('sanitize_text_field', (array)($_POST['test_types'] ?? []))))),
        // Multipaction Test fields
        'mp_package_size'       => (function() {
            $size = [
                'l' => sanitize_text_field($_POST['mp_package_l'] ?? ''),
                'b' => sanitize_text_field($_POST['mp_package_b'] ?? ''),
                'h' => sanitize_text_field($_POST['mp_package_h'] ?? ''),
            ];
            return json_encode($size);
        })(),
        'mp_test_profile_attach'=> sanitize_text_field($_POST['mp_test_profile_attach'] ?? ''),
        'mp_test_profile_file'  => (function() {
            if (!empty($_FILES['mp_test_profile_file']['name'])) {
                if (!function_exists('wp_handle_upload')) require_once ABSPATH . 'wp-admin/includes/file.php';
                $upload = wp_handle_upload($_FILES['mp_test_profile_file'], ['test_form' => false]);
                return !empty($upload['url']) ? $upload['url'] : ($_POST['mp_test_profile_file_existing'] ?? '');
            }
            return sanitize_text_field($_POST['mp_test_profile_file_existing'] ?? '');
        })(),
        'mp_thermocouples'      => max(0, intval($_POST['mp_thermocouples'] ?? 0)),
        'mp_ft_rf_qty'          => intval($_POST['mp_ft_rf_qty'] ?? 0),
        'mp_ft_elec_qty'        => intval($_POST['mp_ft_elec_qty'] ?? 0),
        'mp_ft_others_spec'     => sanitize_text_field($_POST['mp_ft_others_spec'] ?? ''),
        'mp_ft_others_qty'      => intval($_POST['mp_ft_others_qty'] ?? 0),
        'mp_special_instructions'=> sanitize_textarea_field($_POST['mp_special_instructions'] ?? ''),
        // TVC fields
                'tvc_package_size'      => (function() {
            $size = [
                'l' => sanitize_text_field($_POST['tvc_package_l'] ?? ''),
                'b' => sanitize_text_field($_POST['tvc_package_b'] ?? ''),
                'h' => sanitize_text_field($_POST['tvc_package_h'] ?? ''),
            ];
            return json_encode($size);
        })(),

                'tvc_vacuum_range'      => (function() {
            $vac = [
                'mantissa' => sanitize_text_field($_POST['tvc_vacuum_mantissa'] ?? ''),
                'exponent' => sanitize_text_field($_POST['tvc_vacuum_exponent'] ?? ''),
            ];
            return json_encode($vac);
        })(),

        
        'tvc_temp_hot'          => sanitize_text_field($_POST['tvc_temp_hot'] ?? ''),
        'tvc_temp_hot_tol'      => sanitize_text_field($_POST['tvc_temp_hot_tol'] ?? ''),
        'tvc_temp_cold'         => sanitize_text_field($_POST['tvc_temp_cold'] ?? ''),
        'tvc_temp_cold_tol'     => sanitize_text_field($_POST['tvc_temp_cold_tol'] ?? ''),
        'tvc_duration_hot'      => sanitize_text_field($_POST['tvc_duration_hot'] ?? ''),
        'tvc_duration_cold'     => sanitize_text_field($_POST['tvc_duration_cold'] ?? ''),
                'tvc_cycles_required'   => intval($_POST['tvc_cycles_required'] ?? 0),

        'tvc_start_cycle'       => sanitize_text_field($_POST['tvc_start_cycle'] ?? ''),
                'tvc_thermocouples'     => intval($_POST['tvc_thermocouples'] ?? 0),

                'tvc_instructions'      => sanitize_textarea_field($_POST['tvc_instructions'] ?? ''),
        'tvc_other_tests'       => sanitize_textarea_field($_POST['tvc_other_tests'] ?? ''),

        // VCM fields
        'vcm_samples_json'      => (function() {
            $data = [];
            $descs = $_POST['vcm_desc'] ?? [];
            for($i=0; $i<12; $i++) {
                if (!empty($descs[$i])) {
                    $data[] = [
                        'desc'   => sanitize_text_field($descs[$i]),
                        'sample' => sanitize_text_field($_POST['vcm_sample'][$i] ?? ''),
                        'qty'    => sanitize_text_field($_POST['vcm_qty'][$i] ?? ''),
                        'others' => sanitize_text_field($_POST['vcm_others'][$i] ?? ''),
                    ];
                }
            }
            return json_encode($data);
        })(),
                'vcm_vacuum_req'        => (function() {
            $vac = [
                'mantissa' => sanitize_text_field($_POST['vcm_vacuum_mantissa'] ?? ''),
                'exponent' => sanitize_text_field($_POST['vcm_vacuum_exponent'] ?? ''),
            ];
            return json_encode($vac);
        })(),

                'vcm_duration'          => intval($_POST['vcm_duration'] ?? 0),

                'vcm_samples_loaded'    => intval($_POST['vcm_samples_loaded'] ?? 0),

                'vcm_temp_hot_bar'      => uhv_form_sanitize_opt_numeric('vcm_temp_hot_bar'),
        'vcm_temp_hot_bar_tol'  => uhv_form_sanitize_opt_numeric('vcm_temp_hot_bar_tol'),

                'vcm_temp_cold_bar'     => uhv_form_sanitize_opt_numeric('vcm_temp_cold_bar'),
        'vcm_temp_cold_bar_tol' => uhv_form_sanitize_opt_numeric('vcm_temp_cold_bar_tol'),

        'gauge_calibration_json'=> (function() {
            $gauges = [];
            $makes = $_POST['gauge_make'] ?? [];
            for($i=0; $i<4; $i++) {
                if (!empty($makes[$i])) {
                    $gauges[] = [
                        'make'  => sanitize_text_field($makes[$i]),
                        'model' => sanitize_text_field($_POST['gauge_model'][$i] ?? ''),
                        'slno'  => sanitize_text_field($_POST['gauge_slno'][$i] ?? ''),
                        'range' => sanitize_text_field($_POST['gauge_range'][$i] ?? ''),
                    ];
                }
            }
            $refs = array_map('sanitize_text_field', (array)($_POST['gauge_refs'] ?? []));
            $remarks = $_POST['gauge_refs_remarks'] ?? [];
            $refs_full = [];
            foreach($refs as $rname) {
                $refs_full[$rname] = sanitize_text_field($remarks[$rname] ?? '');
            }
            return json_encode(['gauges' => $gauges, 'refs' => $refs, 'refs_full' => $refs_full]);
        })(),
        'corona_test_json'      => (function() {
            $data = [];
            $descs = $_POST['corona_desc'] ?? [];
            for($i=0; $i<3; $i++) {
                if (!empty($descs[$i])) {
                    $data[] = [
                        'desc'     => sanitize_text_field($descs[$i]),
                        'qty'      => sanitize_text_field($_POST['corona_qty'][$i] ?? ''),
                        'vacuum'   => sanitize_text_field($_POST['corona_vacuum'][$i] ?? ''),
                        'duration' => sanitize_text_field($_POST['corona_duration'][$i] ?? ''),
                        'remarks'  => sanitize_text_field($_POST['corona_remarks'][$i] ?? ''),
                    ];
                }
            }
            return json_encode($data);
        })(),
        'other_special_test_desc' => sanitize_textarea_field($_POST['other_special_test_desc'] ?? ''),
        'bombing_leak_test_json' => (function() {
            $data = [];
            $names = $_POST['bombing_name'] ?? [];
            for($i=0; $i<5; $i++) {
                if (!empty($names[$i])) {
                    $data[] = [
                        'name'     => sanitize_text_field($names[$i]),
                        'qty'      => sanitize_text_field($_POST['bombing_qty'][$i] ?? ''),
                        'pressure' => sanitize_text_field($_POST['bombing_pressure'][$i] ?? ''),
                        'dwell'    => sanitize_text_field($_POST['bombing_dwell'][$i] ?? ''),
                        'rate'     => sanitize_text_field($_POST['bombing_rate'][$i] ?? ''),
                    ];
                }
            }
            return json_encode($data);
        })(),
        'msld_samples_json'      => (function() {
            $data = [];
            $qtys = $_POST['msld_qty'] ?? [];
            $remarks = $_POST['msld_remarks'] ?? [];
            $others_spec = sanitize_text_field($_POST['msld_others_spec'] ?? '');
            $msld_rows = [
                "Heat pipe / HMC cases",
                "Thermal / Electrical / RF / Thermocouple feedthrough",
                "Shrouds / Bellows",
                "Vacuum lines & fittings",
                "Wave guides / Valves / Gauges",
                "Others"
            ];
            foreach($msld_rows as $idx => $desc) {
                $row = [
                    'qty'     => sanitize_text_field($qtys[$idx] ?? ''),
                    'remarks' => sanitize_text_field($remarks[$idx] ?? ''),
                ];
                if ($desc === "Others") {
                    $row['others_spec'] = $others_spec;
                }
                $data[] = $row;
            }
            return json_encode($data);
        })(),
            ];
            
            $operation_success = false;
            
            // Try to UPDATE existing draft if draft_id provided
            if ($draft_id > 0) {
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, status, user_id FROM {$table} WHERE id=%d AND user_id=%d AND status IN ('draft_indenter','qa_rejected','rejected','recheck_indenter')", 
                    $draft_id, $user->ID
                ));
                
                if ($existing) {
                    if (in_array($existing->status, ['qa_rejected', 'rejected', 'recheck_indenter'])) {
                        // Clear any prior review/rejection data so it starts fresh in the workflow
                        $submit_data['qa_decision']      = '';
                        $submit_data['qa_remarks']       = '';
                        $submit_data['qa_review_date']   = null;
                        $submit_data['qa_reviewer_name'] = '';
                        $submit_data['reviewed_by']      = '';
                        $submit_data['manager_comment']  = '';
                        $submit_data['approval_date']    = null;
                        $submit_data['manager_action']   = '';
                        $submit_data['manager_decision_date'] = null;
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
                $final_id = ($draft_id > 0) ? $draft_id : $wpdb->insert_id;
                
                // Standardize TR numbering
                $tr_no = 'REQ' . str_pad($final_id, 5, '0', STR_PAD_LEFT);
                $wpdb->update($table, ['test_requisition_no' => $tr_no], ['id' => $final_id]);
                
                uhv_log_history($wpdb, $final_id, 'Form Submitted', 'draft_indenter', $submission_status, $emp->name, $emp->stno, $user_role, $is_resubmit ? 'Form resubmitted after rejection/recheck' : '');

                if ($submission_status === 'pending_qa') {
                    uhv_notify_qa($wpdb, $tr_no, $emp->name, $submit_data['qa_stno'] ?? '');
                } else {
                    uhv_notify_managers($wpdb, $tr_no, $emp->name);
                }
                
                wp_redirect(add_query_arg('uhv_msg', 'submitted', remove_query_arg(['action','view_id','resume_draft','mgr_action','complete_id','prog_id'], get_permalink())));
                exit;
            } else {
                $errors[] = 'Failed to save submission. Please check system logs.';
            }
        }
    }
    
    // If we reach here, there were errors
    if (!empty($errors)) {
        set_transient('uhv_errors_'.$user->ID, $errors, 60);
        if ($user_role === 'manager') {
            wp_redirect(add_query_arg(['mgr_action' => 'create_new', 'uhv_msg' => 'error'], get_permalink()));
        } else {
            wp_redirect(add_query_arg(['action' => 'create_new', 'uhv_msg' => 'error'], get_permalink()));
        }
        exit;
    }
}

/* ---------- QA REVIEW (qa_engineer role OR any nominated employee) ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['qa_decision'])) {
    if (!wp_verify_nonce($_POST['uhv_nonce'], 'uhv_action')) wp_die('Security check failed');
    $form_id = intval($_POST['form_id']);
    // Authorise: dedicated qa_engineer role OR any employee nominated on this specific form
    $is_qa_authorised = ($user_role === 'qa_engineer');
    if (!$is_qa_authorised && $form_id > 0) {
        $nominated_stno = $wpdb->get_var($wpdb->prepare(
            "SELECT qa_stno FROM {$table} WHERE id=%d AND status='pending_qa'", $form_id
        ));
        if (!empty($nominated_stno) && $nominated_stno === $emp->stno) {
            $is_qa_authorised = true;
        }
    }
    if (!$is_qa_authorised) wp_die('Unauthorized');
    $decision   = ($_POST['qa_decision'] === 'accept') ? 'accept' : 'reject';
    // Overhaul: QA Acceptance now forwards to STAFF (pending_staff) instead of directly to manager
    $new_status = ($decision === 'accept') ? 'pending_staff' : 'qa_rejected';
    $wpdb->update($table, [
        'status'           => $new_status,
        'qa_decision'      => $decision,
        'qa_review_date'   => date('Y-m-d H:i:s'),
        'qa_reviewer_name' => $emp->name,
        'qa_remarks'       => sanitize_textarea_field($_POST['qa_remarks'] ?? ''),
    ], ['id' => $form_id]);
    $form = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $form_id));
    if ($decision === 'accept') {
        uhv_log_history($wpdb, $form_id, 'QA Accepted', 'pending_qa', 'pending_staff', $emp->name, $emp->stno, 'qa', sanitize_textarea_field($_POST['qa_remarks'] ?? ''));
        uhv_notify_managers($wpdb, $form->test_requisition_no, $emp->name);
    } else {
        uhv_log_history($wpdb, $form_id, 'QA Rejected', 'pending_qa', 'qa_rejected', $emp->name, $emp->stno, 'qa', sanitize_textarea_field($_POST['qa_remarks'] ?? ''));
        uhv_notify_user($form);
    }
    $msg = ($decision === 'accept') ? 'qa_accepted' : 'qa_rejected';
    // Redirect: manager/deputy → back to their QA review tab; others → their dashboard
    if ($user_role === 'manager') {
        wp_redirect(add_query_arg(['mgr_action'=>'qa_review','uhv_msg'=>$msg],
            remove_query_arg(['qa_view','view_id','complete_id'], get_permalink())
        ));
    } else {
        wp_redirect(add_query_arg('uhv_msg', $msg,
            remove_query_arg(['qa_view', 'view_id', 'complete_id'], get_permalink())
        ));
    }
    exit;
}


/* ---------- STAFF REVIEW (PHASE 1) ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['staff_review_action'])) {
    error_log('UHV: staff_review_action triggered. Action: ' . ($_POST['staff_review_action'] ?? 'none') . ' | Marker: ' . ($_POST['uhv_staff_form_marker'] ?? 'none'));
    
    $uhv_can_staff = (
        $user_role === 'UHV'
        || $user_role === 'manager'
        || (in_array($user_role, ['indenter', 'tr_submitter'], true) && !empty($GLOBALS['is_uhv_section_person']))
    );
    if (!$uhv_can_staff) {
        error_log("UHV Error: Unauthorized staff action by role: $user_role");
        wp_die('Unauthorized: You do not have permission.');
    }
    
    if (!wp_verify_nonce($_POST['uhv_nonce'],'uhv_action')) {
        error_log('UHV Error: Nonce verification failed');
        wp_die('Security check failed. Please refresh the page and try again.');
    }

    $form_id = intval($_POST['form_id'] ?? 0);
    $action = sanitize_text_field($_POST['staff_review_action'] ?? '');
    $form = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $form_id));
    if (!$form) {
        error_log("UHV Error: Form ID $form_id not found");
        wp_die('Invalid form ID');
    }
    
    // Accept either review status OR even pending_manager (to allow edits/resubmission if needed)
    $allowed_statuses = ['pending_staff', 'recheck_staff', 'pending_manager', 'recheck_indenter'];
    if (!in_array($form->status, $allowed_statuses)) {
        error_log("UHV Error: Invalid request state. TR {$form->test_requisition_no} is in status: {$form->status}");
        wp_die('Invalid request state: Current status is ' . $form->status);
    }

    // Extract common risk data for both forward and recheck
    $tests = uhv_get_selected_test_labels($form);
    $risk_data_indexed = [];
    $errors = [];
    foreach ($tests as $idx => $label) {
        $accepted = strtolower(sanitize_text_field($_POST['risk_test_object_accepted'][$idx] ?? ''));
        $risk_as  = strtolower(sanitize_text_field($_POST['risk_assessed_uhv'][$idx] ?? ''));
        $rpn      = sanitize_text_field($_POST['rpn_uhv'][$idx] ?? '');
        $record   = strtolower(sanitize_text_field($_POST['risk_record_uhv'][$idx] ?? ''));
        
        $risk_table_url = $_POST['existing_risk_table_url'][$idx] ?? '';
        $file_key = 'risk_table_file_' . $idx;
        if (!empty($_FILES[$file_key]['name'])) {
            if (!function_exists('wp_handle_upload')) require_once ABSPATH . 'wp-admin/includes/file.php';
            $upload = wp_handle_upload($_FILES[$file_key], ['test_form' => false]);
            if (!empty($upload['url'])) {
                $risk_table_url = $upload['url'];
            }
        }

        // Only validate if forwarding to manager
        if ($action === 'forward_manager') {
            if (!in_array($accepted, ['yes','no'])) $errors[] = "{$label}: Test request accepted is required";
            if (!in_array($risk_as, ['yes','no'])) $errors[] = "{$label}: Risk assessed is required";
            if (!in_array($rpn, ['lt4','gte5'])) $errors[] = "{$label}: RPN is required";
            if (!in_array($record, ['yes','no','na'])) $errors[] = "{$label}: Risk record is required";
        }
        
        $risk_data_indexed[] = [
            'test_label'           => $label,
            'test_object_accepted' => ucfirst($accepted),
            'risk_assessed_uhv'    => ucfirst($risk_as),
            'rpn_uhv'              => $rpn,
            'risk_record_uhv'      => $record,
            'risk_table_url'       => $risk_table_url,
        ];
    }
    $risk_json_str = wp_json_encode($risk_data_indexed);
    $comment = sanitize_textarea_field($_POST['staff_review_comment'] ?? '');

    if ($action === 'recheck_indenter') {
        if (empty($comment)) {
            // Recheck requires a comment/instruction for the user
            set_transient('uhv_errors_'.$user->ID, ['Recheck Instructions are required — please enter a comment for the user.'], 60);
            $redirect_url = add_query_arg(['complete_id'=> $form_id, 'uhv_msg'=>'validation_error'], get_permalink());
            wp_redirect($redirect_url);
            exit;
        }
        $wpdb->update($table, [
            'status'             => 'recheck_indenter',
            'manager_comment'    => $comment,
            'per_test_risk_json' => $risk_json_str
        ], ['id' => $form_id]);
        uhv_log_history($wpdb, $form_id, 'Staff Sent for Recheck', $form->status, 'recheck_indenter', $emp->name, $emp->stno, 'staff', $comment);
        wp_redirect(add_query_arg('uhv_msg', 'recheck_sent', remove_query_arg(['view_id','complete_id'], get_permalink())));
        exit;
    }

    // forward_manager — validate all fields are filled
    if (!empty($errors)) {
        error_log('UHV Validation Errors (forward_manager): ' . implode(', ', $errors));
        // Save progress anyway so staff don't lose their selections
        $wpdb->update($table, ['per_test_risk_json' => $risk_json_str], ['id' => $form_id]);
        set_transient('uhv_errors_'.$user->ID, $errors, 60);
        $redirect_url = add_query_arg(['complete_id'=> $form_id, 'uhv_msg'=>'validation_error'], get_permalink());
        wp_redirect($redirect_url);
        exit;
    }

    $wpdb->update($table, [
        'per_test_risk_json' => $risk_json_str,
        'manager_comment'    => $comment,
        'status'             => 'pending_manager',
        'staff_review_date'  => current_time('mysql'),
        'reviewed_by'        => esc_html($emp->name),
    ], ['id' => $form_id]);
    uhv_log_history($wpdb, $form_id, 'Staff Review Completed', $form->status, 'pending_manager', $emp->name, $emp->stno, 'staff', 'Risk assessment forwarded to manager');
    wp_redirect(add_query_arg('uhv_msg', 'staff_reviewed', remove_query_arg(['complete_id','view_id'], get_permalink())));
    exit;
}

/* ---------- MANAGER APPROVE / REJECT / RECHECK ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action_type'])) {
    if ($user_role!=='manager') wp_die('Unauthorized');
    if (!wp_verify_nonce($_POST['uhv_nonce'],'uhv_action')) wp_die('Security check failed');

    error_log('Manager action triggered: ' . ($_POST['action_type'] ?? 'unknown'));

    $form_id    = intval($_POST['form_id']);
    $action_raw = sanitize_text_field($_POST['action_type'] ?? '');

    // ── STEP 1: Manager Decision (approve/reject/recheck) ──
    // This fires when the decision buttons are clicked (before env form is shown)
    if (in_array($action_raw, ['approve','reject','recheck'])) {
        $comment = sanitize_textarea_field($_POST['manager_comment'] ?? '');
        if (($action_raw === 'reject' || $action_raw === 'recheck') && empty($comment)) {
            // Redirect back with error if comment is missing for reject/recheck
            wp_redirect(add_query_arg(['view_id'=>$form_id,'uhv_msg'=>'comment_required'], get_permalink()));
            exit;
        }

        if ($action_raw === 'approve') {
            $comment = sanitize_textarea_field($_POST['manager_comment'] ?? '');
            
            // Overhaul: Manager no longer fills Section A. We just approve.
            // Manager may still upload a file if they wish to attach a formal approval record.
            $risk_file_url = $wpdb->get_var($wpdb->prepare("SELECT risk_form_file FROM {$table} WHERE id=%d", $form_id));
            if (!empty($_FILES['risk_assessment_file']['name'])) {
                if (!function_exists('wp_handle_upload')) require_once ABSPATH . 'wp-admin/includes/file.php';
                $upload = wp_handle_upload($_FILES['risk_assessment_file'], ['test_form' => false]);
                if (!empty($upload['url'])) {
                    $risk_file_url = $upload['url'];
                }
            }

            $wpdb->update($table, [
                'status'               => 'approved',
                'manager_id'           => $user->ID,
                'manager_action'       => 'approve',
                'manager_comment'      => $comment,
                'reviewed_by'          => esc_html($emp->name),
                'approval_date'        => date('Y-m-d H:i:s'),
                'manager_decision_date'=> date('Y-m-d H:i:s'),
                'risk_form_file'       => $risk_file_url,
            ], ['id' => $form_id]);

            uhv_log_history($wpdb, $form_id, 'Manager Approved', 'pending_manager', 'approved', $emp->name, $emp->stno, 'manager', $comment);
            if ($wpdb->last_error) {
                error_log('UHV Manager approve DB error: ' . $wpdb->last_error);
            }
            
            // Assign Final TR number: YYYY_MM_UHV_XXXXX (Sequential)
            $cur_tr = $wpdb->get_var($wpdb->prepare("SELECT test_requisition_no FROM {$table} WHERE id=%d", $form_id));
            if ($cur_tr && preg_match('/^(DRAFT|PENDING|REQ)/', $cur_tr)) {
                $prefix = date('Y-m') . '_UHV_';
                // Find the highest sequence number for the current month's prefix
                $last_tr = $wpdb->get_var($wpdb->prepare(
                    "SELECT test_requisition_no FROM {$table} 
                     WHERE test_requisition_no LIKE %s 
                     ORDER BY test_requisition_no DESC LIMIT 1",
                    $prefix . '%'
                ));

                $next_seq = 1;
                if ($last_tr) {
                    $parts = explode('_', $last_tr);
                    $last_seq = intval(end($parts));
                    $next_seq = $last_seq + 1;
                }

                $tr_no = $prefix . str_pad($next_seq, 6, '0', STR_PAD_LEFT); // User requested 000001 (6 digits) in example 
                $wpdb->query($wpdb->prepare("UPDATE {$table} SET test_requisition_no = %s WHERE id = %d", $tr_no, $form_id));
                if ($wpdb->last_error) error_log('UHV approve TR format error: ' . $wpdb->last_error);
            }
            $form = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $form_id));
            uhv_notify_user($form);
            uhv_notify_uhv($wpdb, $form);
            
            wp_redirect(add_query_arg('uhv_msg', 'approved', remove_query_arg(['view_id','mgr_step'], get_permalink())));
            exit;

        } elseif ($action_raw === 'reject') {
            $wpdb->update($table, [
                'status'               => 'rejected',
                'manager_id'           => $user->ID,
                'manager_action'       => 'reject',
                'manager_comment'      => $comment,
                'reviewed_by'          => esc_html($emp->name),
                'approval_date'        => date('Y-m-d H:i:s'),
                'manager_decision_date'=> date('Y-m-d H:i:s'),
            ], ['id' => $form_id]);
            uhv_log_history($wpdb, $form_id, 'Manager Rejected', 'pending_manager', 'rejected', $emp->name, $emp->stno, 'manager', $comment);
            $form = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $form_id));
            uhv_notify_user($form);
            wp_redirect(add_query_arg('uhv_msg','rejected', remove_query_arg(['view_id'], get_permalink())));
            exit;

        } elseif ($action_raw === 'recheck') {
            // Send back to indenter for correction — use dedicated 'recheck_indenter' status
            // so it appears separately from plain drafts in the indenter dashboard
            $wpdb->update($table, [
                'status'               => 'pending_staff',
                'manager_action'       => 'recheck',
                'manager_comment'      => $comment,
                'reviewed_by'          => esc_html($emp->name),
                'manager_decision_date'=> date('Y-m-d H:i:s'),
            ], ['id' => $form_id]);
            uhv_log_history($wpdb, $form_id, 'Manager Sent for Recheck', 'pending_manager', 'pending_staff', $emp->name, $emp->stno, 'manager', $comment);
            $form = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $form_id));
            uhv_notify_user($form);
            wp_redirect(add_query_arg('uhv_msg','recheck_sent', remove_query_arg(['view_id'], get_permalink())));
            exit;
        }
    }
}

/* ---------- UHV SAVE DRAFT (any logged-in employee) ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_draft'])) {
    if (!is_user_logged_in()) wp_die('Unauthorized'); // any authenticated employee may save
    if (!wp_verify_nonce($_POST['uhv_nonce'],'uhv_action')) wp_die('Security check failed');
    
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
    $yes_no_fields = ['test_on_time'];
    
    foreach ($yes_no_fields as $field) {
        $value = strtolower(trim($_POST[$field] ?? ''));
        if ($value && $value !== 'yes' && $value !== 'no') {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' must be "Yes" or "No"';
        }
    }
    
    // If errors exist, show them
    if (!empty($errors)) {
        set_transient('uhv_errors_'.$user->ID, $errors, 60);
        wp_redirect(add_query_arg('uhv_msg', 'validation_error', add_query_arg('complete_id', $form_id, get_permalink())));
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
    
    // Normalize UHV Staff fields (Section B & C only)
    $update_data = [
        'test_accepted_by'              => sanitize_text_field($_POST['test_accepted_by'] ?? ''),
        'test_started_datetime'         => str_replace('T', ' ', sanitize_text_field($_POST['test_started_datetime'] ?? '')),
        'test_completed_datetime'       => str_replace('T', ' ', sanitize_text_field($_POST['test_completed_datetime'] ?? '')),
        'test_duration'                 => $test_duration,
        'test_on_time'                  => strtolower(trim($_POST['test_on_time'] ?? '')) === 'yes' ? 'Yes' : (trim($_POST['test_on_time'] ?? '') === '' ? '' : 'No'),
        'test_code'                     => sanitize_text_field($_POST['test_code'] ?? ''),
        'specimen_collected_by_name'    => $specimen_name,
        'specimen_collected_by_sig'     => $specimen_sig,
        'verification_closed_by_name'   => $verify_name,
        'verification_closed_by_sig'    => $verify_sig,
        'draft_saved_at'                => date('Y-m-d H:i:s'),
        'draft_saved_by'                => $emp->name,
    ];
    
    // NEW: Also save Section A (per-test risk) if we are in Phase 1 or just want to persist it
    if (isset($_POST['uhv_staff_form_marker'])) {
        $form_raw = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $form_id));
        if ($form_raw) {
            $testsArr = uhv_get_selected_test_labels($form_raw);
            $risk_data_collect = [];
            foreach ($testsArr as $idx => $label) {
                $accepted = strtolower(sanitize_text_field($_POST['risk_test_object_accepted'][$idx] ?? ''));
                $risk_as  = strtolower(sanitize_text_field($_POST['risk_assessed_uhv'][$idx] ?? ''));
                $rpn      = sanitize_text_field($_POST['rpn_uhv'][$idx] ?? '');
                $record   = strtolower(sanitize_text_field($_POST['risk_record_uhv'][$idx] ?? ''));
                
                $risk_table_url = $_POST['existing_risk_table_url'][$idx] ?? '';
                $file_key = 'risk_table_file_' . $idx;
                if (!empty($_FILES[$file_key]['name'])) {
                    if (!function_exists('wp_handle_upload')) require_once ABSPATH . 'wp-admin/includes/file.php';
                    $upload = wp_handle_upload($_FILES[$file_key], ['test_form' => false]);
                    if (!empty($upload['url'])) $risk_table_url = $upload['url'];
                }

                $risk_data_collect[] = [
                    'test_label'           => $label,
                    'test_object_accepted' => ucfirst($accepted),
                    'risk_assessed_uhv'    => ucfirst($risk_as),
                    'rpn_uhv'              => $rpn,
                    'risk_record_uhv'      => $record,
                    'risk_table_url'       => $risk_table_url,
                ];
            }
            $update_data['per_test_risk_json'] = wp_json_encode($risk_data_collect);
            $update_data['manager_comment']    = sanitize_textarea_field($_POST['staff_review_comment'] ?? '');
        }
    }
    
    $wpdb->update($table, $update_data, ['id'=>$form_id]);
    uhv_log_history($wpdb, $form_id, 'Draft Saved', 'any', 'any', $emp->name, $emp->stno, 'staff', 'Staff form draft saved (including Section A if applicable)');
    
    wp_redirect(add_query_arg('uhv_msg', 'uhv_draft_saved', remove_query_arg(['action','complete_id','view_id'], get_permalink())));
    exit;
}

/* ---------- UHV COMPLETE (any logged-in employee) ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['complete_uhv'])) {
    if (!is_user_logged_in()) wp_die('Unauthorized'); // any authenticated employee may complete
    if (!wp_verify_nonce($_POST['uhv_nonce'],'uhv_action')) wp_die('Security check failed');
    
    $form_id = intval($_POST['form_id']);
    $errors = [];
    
    // ===== MANDATORY FIELD VALIDATION =====
    $mandatory_fields = [
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
    $yes_no_fields = ['test_on_time']; // Only test_on_time remains for staff execution
    
    foreach ($yes_no_fields as $field) {
        $value = strtolower(trim($_POST[$field] ?? ''));
        if ($value && $value !== 'yes' && $value !== 'no') {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ': Please enter "Yes" or "No"';
        }
    }
    
    // Show validation errors if any
    if (!empty($errors)) {
        set_transient('uhv_errors_'.$user->ID, $errors, 60);
        wp_redirect(add_query_arg('uhv_msg', 'validation_error', add_query_arg('complete_id', $form_id, get_permalink())));
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
    
    // Normalize UHV Staff fields (Section B & C only)
    $update_data = [
        'test_accepted_by'              => sanitize_text_field($_POST['test_accepted_by'] ?? ''),
        'test_started_datetime'         => str_replace('T', ' ', sanitize_text_field($_POST['test_started_datetime'] ?? '')),
        'test_completed_datetime'       => str_replace('T', ' ', sanitize_text_field($_POST['test_completed_datetime'] ?? '')),
        'test_duration'                 => $test_duration,
        'test_on_time'                  => strtolower(trim($_POST['test_on_time'] ?? '')) === 'yes' ? 'Yes' : 'No',
        'test_code'                     => sanitize_text_field($_POST['test_code'] ?? ''),
        'specimen_collected_by_name'    => $specimen_name,
        'specimen_collected_by_sig'     => $specimen_sig,
        'verification_closed_by_name'   => $verify_name,
        'verification_closed_by_sig'    => $verify_sig,
        'status'                        => 'completed',
        'completion_date'               => date('Y-m-d H:i:s'),
    ];

    // Collect Bombing staff data if applicable
    $req_obj = $wpdb->get_row($wpdb->prepare("SELECT test_types FROM {$table} WHERE id=%d", $form_id));
    if ($req_obj && stripos($req_obj->test_types ?? '', 'Bombing') !== false) {
        $blu = [];
        $bnm  = $_POST['bombing_load_name'] ?? [];
        if (!empty($bnm)) {
            $brcv = $_POST['bombing_load_received_on'] ?? [];
            $bqty = $_POST['bombing_load_qty'] ?? [];
            $bprs = $_POST['bombing_load_pressure'] ?? [];
            $bchm = $_POST['bombing_load_chamber'] ?? [];
            $bdur = $_POST['bombing_load_duration'] ?? [];
            $bon  = $_POST['bombing_load_on'] ?? [];
            for($i=0; $i<count($bnm); $i++) {
                if(!empty($bnm[$i])) {
                    $blu[] = [
                        'received_on' => sanitize_text_field($brcv[$i]??''),
                        'name'        => sanitize_text_field($bnm[$i]??''),
                        'qty'         => sanitize_text_field($bqty[$i]??''),
                        'pressure'    => sanitize_text_field($bprs[$i]??''),
                        'chamber'     => sanitize_text_field($bchm[$i]??''),
                        'duration'    => sanitize_text_field($bdur[$i]??''),
                        'on'          => sanitize_text_field($bon[$i]??''),
                    ];
                }
            }
        }
        $bombing_staff_data = [
            'checklist' => (array)($_POST['bombing_checklist'] ?? []),
            'loading_unloading' => $blu,
            'loaded_by' => sanitize_text_field($_POST['bombing_loaded_by'] ?? ''),
            'unloaded_by' => sanitize_text_field($_POST['bombing_unloaded_by'] ?? ''),
            'msld' => [
                'calibrated' => sanitize_text_field($_POST['bombing_msld_calibrated'] ?? ''),
                'used'       => (array)($_POST['bombing_msld_used'] ?? []),
                'started'    => sanitize_text_field($_POST['bombing_msld_started'] ?? ''),
                'ended'      => sanitize_text_field($_POST['bombing_msld_ended'] ?? ''),
                'staff'      => sanitize_text_field($_POST['bombing_msld_staff'] ?? ''),
                'sig'        => sanitize_text_field($_POST['bombing_msld_sig'] ?? ''),
            ]
        ];
        $update_data['bombing_staff_json'] = json_encode($bombing_staff_data);
    }
    
    $wpdb->update($table, $update_data, ['id'=>$form_id]);
    uhv_log_history($wpdb, $form_id, 'Test Completed', 'approved', 'completed', $emp->name, $emp->stno, 'staff', 'UHV Test form completed and closed');
    
    wp_redirect(add_query_arg('uhv_msg', 'uhv_completed', remove_query_arg(['action','complete_id','view_id'], get_permalink())));
    exit;
}

// ========== ALL POST HANDLERS DONE — NOW SAFE TO SEND HEADERS ==========

// Manager "Change Decision" — resets manager_action so step 1 shows again
if ($user_role === 'manager' && isset($_GET['mgr_step']) && $_GET['mgr_step'] === 'reset' && isset($_GET['view_id'])) {
    $reset_id = intval($_GET['view_id']);
    if ($reset_id) {
        $wpdb->update($table, ['manager_action' => '', 'manager_comment' => '', 'reviewed_by' => ''], ['id' => $reset_id, 'status' => 'pending']);
    }
    wp_redirect(add_query_arg('view_id', $reset_id, remove_query_arg(['mgr_step'], get_permalink())));
    exit;
}

// Route `action=staff_dashboard&request_id=ID` → internal `complete_id=ID`
// This validates the request exists and is approved, then redirects to the
// existing UHV staff handler (which shows the staff form when `complete_id` is present).
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
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;font-size:18px;line-height:1.6}
.container{max-width:1800px;margin:30px auto;padding:40px;background:#fff}
.form-container{max-width:1450px;margin:auto;background:#fff;padding:40px}
table{width:100%;border-collapse:collapse;font-size:17px;margin-bottom:20px}
th,td{border:1px solid #000;padding:14px 18px;vertical-align:middle}
th{background:#f5f5f5;font-weight:700;font-size:18px}
.label{background:#f5f5f5;font-weight:700}
.block{width:100%;height:50px;border:1px solid #000;padding:12px 16px;font-size:17px}
textarea{width:100%;min-height:110px;border:1px solid #000;resize:vertical;padding:14px;font-size:17px;font-family:inherit;line-height:1.5}
.request-card{border:1px solid #ddd;margin:25px 0;padding:35px;background:#fff;box-shadow: 0 4px 15px rgba(0,0,0,0.05);border-radius:8px;}
.request-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:30px;padding-bottom:22px;border-bottom:3px solid #000}
.badge{padding:10px 22px;font-size:15px;font-weight:700;letter-spacing:.8px;border-radius:5px;display:inline-block;box-shadow: 0 2px 5px rgba(0,0,0,0.1);}
.badge-pending{background:#ffc107;color:#000}
.badge-approved{background:#28a745;color:#fff}
.badge-rejected{background:#dc3545;color:#fff}
.badge-completed{background:#000;color:#fff}
.badge-pending-qa{background:#6f42c1;color:#fff}
.badge-qa-rejected{background:#fd7e14;color:#fff}
.manager-fields{margin-top:30px;padding:30px;background:#fff;border:3px solid #000;border-radius:6px;}
.manager-fields h4{margin:0 0 22px 0;font-size:19px;font-weight:700;text-transform:uppercase;letter-spacing:1px}
.btn{padding:14px 32px;border:none;cursor:pointer;font-weight:700;font-size:16px;text-transform:uppercase;letter-spacing:1px;transition:all .2s cubic-bezier(0.4, 0, 0.2, 1);text-decoration:none;display:inline-block;border-radius:5px;box-shadow:0 3px 6px rgba(0,0,0,0.15);}
.btn:hover{opacity:.9; transform:translateY(-1px); box-shadow:0 5px 12px rgba(0,0,0,0.2);}
.btn:active{transform:translateY(0);}
.btn-primary{background:#000;color:#fff}
.btn-success{background:#28a745;color:#fff}
.btn-approve{background:#28a745;color:#fff}
.btn-reject{background:#dc3545;color:#fff}
.btn-draft{background:#17a2b8;color:#fff}
.btn-info{background:#17a2b8;color:#fff}
.btn-view{background:#000;color:#fff;padding:12px 24px;font-size:15px;box-shadow:none;}
.btn-submit{background:#000;color:#fff;padding:18px 45px;border:none;cursor:pointer;font-weight:700;font-size:18px;text-transform:uppercase;border-radius:6px;box-shadow:0 4px 10px rgba(0,0,0,0.25);}
.btn-complete-submit{background:#28a745;color:#fff;padding:18px 40px;border:none;cursor:pointer;font-weight:700;font-size:17px;text-transform:uppercase;border-radius:6px;box-shadow:0 4px 10px rgba(40,167,69,0.2);}
.btn-test-details{background:#007bff;color:#fff;padding:12px 24px;font-size:15px;margin-left:10px;box-shadow:none;}
.view-only{background:#f5f5f5;color:#000}
.role-indicator{padding:22px 40px;margin-bottom:35px;font-weight:700;text-align:center;background:#000;color:#fff;font-size:18px;text-transform:uppercase;letter-spacing:2px;border-radius:6px;box-shadow:0 4px 10px rgba(0,0,0,0.1);}
.list-table{width:100%;border-collapse:collapse;margin-top:30px;border:2px solid #000;border-radius:8px;overflow:hidden;}
.list-table th{background:#000;color:#fff;padding:18px;text-align:left;font-weight:700;font-size:15px;text-transform:uppercase;letter-spacing:1px}
.list-table td{padding:18px;border-bottom:1px solid #eee;font-size:17px}
.list-table tbody tr{background:#fff}
.list-table tbody tr:hover{background:#f8f9fa}
.draft-notice{background:#fff3cd;border:2px solid #ffc107;padding:18px 24px;margin:25px 0;border-radius:6px;font-size:16px;}
.qa_field{display:none;}
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:25px;margin-bottom:45px}
.stat-card{padding:32px 28px;border:3px solid;text-align:center;border-radius:12px;box-shadow:0 4px 8px rgba(0,0,0,0.05);transition:all .3s;}
.stat-card:hover{transform:translateY(-3px);box-shadow:0 8px 16px rgba(0,0,0,0.1);}
.stat-card .stat-num{font-size:54px;font-weight:800;line-height:1;margin-bottom:10px}
.stat-card .stat-lbl{font-size:15px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px}
.sc-pending{border-color:#ffc107;background:#fffdf0;color:#856404}
.sc-approved{border-color:#28a745;background:#f0fff4;color:#155724}
.mgr-tabs{display:flex;gap:0;margin-bottom:35px;border-bottom:3px solid #000;flex-wrap:wrap;}
.mgr-tab{padding:16px 30px;font-size:15px;font-weight:700;text-transform:uppercase;letter-spacing:1px;cursor:pointer;border:3px solid transparent;border-bottom:none;margin-bottom:-3px;background:#fff;color:#666;text-decoration:none;display:inline-block;white-space:nowrap;transition:all .2s;}
.mgr-tab.active{background:#000;color:#fff;border-color:#000}
.mgr-tab:hover:not(.active){background:#f5f5f5;color:#000}
.mgr-tab-new{background:#28a745!important;color:#fff!important;margin-left:auto;border-color:#28a745!important}
.radio-row{display:flex;align-items:center;gap:15px;margin:10px 0;flex-wrap:wrap;}
.radio-item{display:flex;align-items:center;gap:8px;flex:1;min-width:180px;}
h1{font-size:26px;font-weight:600;text-transform:uppercase;letter-spacing:1px;margin-bottom:25px}
h2{font-size:24px;font-weight:600}
h3{font-size:20px;font-weight:600;margin:25px 0 15px 0;text-transform:uppercase;letter-spacing:.8px}
p{font-size:16px;line-height:1.6}
.btn-view{background:#000;color:#fff;padding:8px 18px;border-radius:4px;text-decoration:none;font-size:14px;display:inline-block;border:none;cursor:pointer;font-weight:600;text-transform:uppercase;letter-spacing:.8px}
.btn-view:hover{background:#333}

.uniform-check-label { display: inline-flex; align-items: center; gap: 10px; cursor: pointer; font-weight: 500; font-size: 16px; margin-right: 25px; user-select: none; }
.uniform-check-input { width: 22px; height: 22px; cursor: pointer; accent-color: #000; }
</style>

<?php

// =====================================================================
//  SHARED: REQUEST FORM HTML
// =====================================================================
function uhv_request_form($emp, $draft=null, $ajax_url='') { 
    $d = $draft;
    // resubmit_mode: manager-rejected = locked (only chamber editable); qa_rejected/recheck_indenter = fully editable
    $resubmit         = (!empty($d->status) && $d->status === 'rejected');           // manager rejected — partial lock
    $qa_rejected_edit = (!empty($d->status) && $d->status === 'qa_rejected');         // qa rejected — all editable
    $recheck_edit     = (!empty($d->status) && $d->status === 'recheck_indenter');    // manager sent for recheck — all editable
    
    // Parse Multipaction package size
    $mp_size = ['l'=>'', 'b'=>'', 'h'=>''];
    if (!empty($d->mp_package_size)) {
        $tmp = json_decode($d->mp_package_size, true);
        if (is_array($tmp)) $mp_size = array_merge($mp_size, $tmp);
    }

    $ro       = $resubmit ? 'readonly' : '';
    $ro_bg    = $resubmit ? 'background:#f5f5f5;' : '';
?>
<form method="post" enctype="multipart/form-data" id="uhv_request_form">
<?php wp_nonce_field('uhv_action','uhv_nonce'); ?>
<?php if (!empty($d->id) && in_array($d->status, ['draft_indenter', 'qa_rejected', 'rejected', 'recheck_indenter'])): ?>
<input type="hidden" name="draft_id" value="<?php echo intval($d->id); ?>">
<?php endif; ?>

<?php if ($resubmit || $qa_rejected_edit || $recheck_edit): ?>
<?php if ($resubmit): ?>
<div style="background:#f8d7da;border:2px solid #dc3545;padding:16px 22px;margin-bottom:24px;border-radius:6px;">
  <strong style="color:#721c24;font-size:15px;">&#10060; Rejected by Manager</strong><br>
  <span style="font-size:14px;color:#555;display:block;margin-top:6px;">
    <strong>Manager Comments:</strong> <em><?php echo esc_html($d->manager_comment ?: '—'); ?></em>
  </span>
  <span style="font-size:13px;color:#721c24;display:block;margin-top:10px;">
    &#128274; All fields are locked. Only <strong>Chamber Interface Requirements</strong> can be edited before resubmitting.
  </span>
</div>
<?php elseif ($recheck_edit): ?>
<div style="background:#fff8e1;border:2px solid #fd7e14;padding:16px 22px;margin-bottom:24px;border-radius:6px;">
  <strong style="color:#7a3e00;font-size:15px;">&#8617; Sent for Recheck by Manager</strong><br>
  <span style="font-size:14px;color:#555;display:block;margin-top:6px;">
    <strong>Manager's Remarks:</strong> <em><?php echo esc_html($d->manager_comment ?: '—'); ?></em>
  </span>
  <span style="font-size:13px;color:#28a745;display:block;margin-top:10px;font-weight:600;">
    &#9998; All fields are editable. Please review, update, and resubmit for approval.
  </span>
</div>
<?php else: ?>
<div style="background:#fff3cd;border:2px solid #fd7e14;padding:16px 22px;margin-bottom:24px;border-radius:6px;">
  <strong style="color:#856404;font-size:15px;">&#9888; Returned by QA Engineer</strong><br>
  <span style="font-size:14px;color:#555;display:block;margin-top:6px;">
    <strong>Remarks:</strong> <em><?php echo esc_html($d->qa_remarks ?: '—'); ?></em>
  </span>
  <span style="font-size:13px;color:#28a745;display:block;margin-top:10px;font-weight:600;">
    &#9998; All fields are editable. Please review, update as needed, and resubmit.
  </span>
</div>
<?php endif; ?>
<?php elseif (!empty($d->indenter_draft_saved_at)): ?>
<div class="draft-notice"><strong>&#128203; Draft Saved:</strong> Last saved by <strong><?php echo esc_html($d->indenter_draft_saved_by ?? 'Unknown'); ?></strong> on <strong><?php echo date('d M Y, h:i A', strtotime($d->indenter_draft_saved_at)); ?></strong></div>
<?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:30px;font-size:18px;">
  <div><strong style="font-size:17px;">UHV LAB</strong><br>ENVIRONMENTAL TEST FACILITY<br><strong>U R Rao Satellite Centre</strong></div>
  <div style="text-align:right">
    <strong style="font-size:17px;">TEST REQUEST FORM</strong><br>UHV<br><br>
    <span style="font-size:17px;color:#856404;background:#fff3cd;border:1px solid #ffc107;padding:6px 14px;display:inline-block;border-radius:4px;">
      &#9432; Test Requisition No. will be assigned upon manager approval
    </span>
  </div>
</div>

<?php
$qa_exists_val     = $d->qa_exists      ?? 'no';
$qa_name_val       = $d->qa_name        ?? '';
$qa_stno_val       = $d->qa_stno        ?? '';
$qa_email_val      = $d->qa_email       ?? '';
$qa_desgn_val      = $d->qa_designation ?? '';
$qa_section_val    = $d->qa_section     ?? '';
$qa_phone_val      = $d->qa_phone       ?? '';
$qa_display        = (strtolower($qa_exists_val) === 'yes') ? 'table-cell' : 'none';
$qa_search_display = (strtolower($qa_exists_val) === 'yes') ? 'block' : 'none';
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
      <!-- Manager-rejected: lock qa_exists -->
      <input type="hidden" name="qa_exists" value="<?php echo esc_attr($qa_exists_val); ?>">
      <span style="font-size:14px;color:#555;font-style:italic;">
        <?php echo strtolower($qa_exists_val) === 'yes' ? 'QA Engineer assigned' : 'No QA Engineer'; ?>
      </span>
    <?php else: ?>
      <!-- Normal / qa_rejected_edit: fully interactive -->
      <input type="hidden" name="qa_exists" id="qa_exists_val" value="<?php echo esc_attr($qa_exists_val); ?>">
      <label class="uniform-check-label">
        <input type="checkbox" class="uniform-check-input" id="qa_exists_yes" <?php echo (strtolower($qa_exists_val)==='yes')?'checked':''; ?> onchange="uhvToggleCb(this,'qa_exists_no','qa_exists_val','yes'); toggleQA();"> Yes
      </label>
      <label class="uniform-check-label">
        <input type="checkbox" class="uniform-check-input" id="qa_exists_no" <?php echo (strtolower($qa_exists_val)!=='yes')?'checked':''; ?> onchange="uhvToggleCb(this,'qa_exists_yes','qa_exists_val','no'); toggleQA();"> No
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
  <td class="qa_field" style="display:<?php echo $qa_display;?>"><input class="block" id="qa_email" name="qa_email" value="<?php echo esc_attr($qa_email_val); ?>" readonly style="background:#f5f5f5;"></td>
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
  <td class="qa_field" style="display:<?php echo $qa_display;?>"><input class="block" id="qa_designation" name="qa_designation" value="<?php echo esc_attr($qa_desgn_val); ?>" readonly style="background:#f5f5f5;"></td>
</tr>
<tr>
  <td class="label">Telephone No.</td>
  <td><input class="block" name="sub_phone" value="<?php echo esc_attr($emp->telephoneno ?? ''); ?>" readonly style="background:#f5f5f5;"></td>
  <td class="qa_field" style="display:<?php echo $qa_display;?>"><input class="block" id="qa_phone" name="qa_phone" value="<?php echo esc_attr($qa_phone_val);?>" readonly style="background:#f5f5f5;"></td>
</tr>
</table>

<!-- ── TEST OBJECT DETAILS (locked in resubmit mode) ── -->
<?php
$saved_types = array_filter(array_map('trim', explode(',', $d->test_types ?? '')));
$mp_checked  = in_array('Multipaction Test', $saved_types) ? 'checked' : '';
$tvc_checked = in_array('Thermal Vacuum Cycling Test', $saved_types) ? 'checked' : '';
$vcm_checked = in_array('VCM (Outgassing) Testing Material', $saved_types) ? 'checked' : '';
$msld_checked = in_array('Leak Test of Vac. Elements using MSLD', $saved_types) ? 'checked' : '';
$gauge_checked = in_array('Calibration of Vacuum Gauges', $saved_types) ? 'checked' : '';
$corona_checked = in_array('Corona / High Altitude Test', $saved_types) ? 'checked' : '';
$other_checked = in_array('Other Special Test', $saved_types) ? 'checked' : '';
$bombing_checked = in_array('Bombing & Fine Leak Detection of Electronic Components', $saved_types) ? 'checked' : '';
?>
<table>
<tr>
  <th colspan="4" style="text-align:left;background:#000;color:#fff;">Test Object Details</th>
</tr>
<tr>
  <th style="width:25%">Name of the Test Object</th>
  <td colspan="3"><input class="block" name="satellite_name" value="<?php echo esc_attr($d->satellite_name ?? ''); ?>" <?php echo $ro; ?> style="<?php echo $ro_bg; ?>" required></td>
</tr>
<tr>
  <th style="width:25%">Test Required on</th>
  <td colspan="3"><input type="date" class="block" name="test_required_on" value="<?php echo esc_attr($d->test_required_on ?? ''); ?>" <?php echo $ro; ?> style="<?php echo $ro_bg; ?>max-width:220px;" required></td>
</tr>
<tr>
  <th style="vertical-align:top;padding-top:12px;">Type of Test <span style="color:#dc3545;">*</span><br><small style="font-weight:400;color:#666;">(Select only one)</small></th>
  <td colspan="3" style="padding:12px 10px;">
    <?php if ($resubmit): ?>
      <!-- Manager-rejected: lock test type -->
      <input type="hidden" name="test_type" value="<?php echo esc_attr($d->test_type ?? ''); ?>">
      <?php foreach ($saved_types as $st): ?>
        <input type="hidden" name="test_types[]" value="<?php echo esc_attr($st); ?>">
      <?php endforeach; ?>
      <span style="font-size:14px;color:#555;font-style:italic;"><?php echo esc_html($d->test_type ?: 'Multipaction Test'); ?></span>
    <?php else: ?>
    <div style="display:flex;gap:30px;flex-wrap:wrap;">
      <label class="uniform-check-label">
        <input type="radio" class="uniform-check-input" name="test_types[]" value="Multipaction Test" id="tt_mp" <?php echo $mp_checked; ?>
               onchange="uhvToggleTestForm()">
        Multipaction Test
      </label>
      <label class="uniform-check-label">
        <input type="radio" class="uniform-check-input" name="test_types[]" value="Thermal Vacuum Cycling Test" id="tt_tvc" <?php echo $tvc_checked; ?>
               onchange="uhvToggleTestForm()">
        Thermal Vacuum Cycling Test
      </label>
      <label class="uniform-check-label">
        <input type="radio" class="uniform-check-input" name="test_types[]" value="VCM (Outgassing) Testing Material" id="tt_vcm" <?php echo $vcm_checked; ?>
               onchange="uhvToggleTestForm()">
        VCM (Outgassing) Testing Material
      </label>
      <label class="uniform-check-label">
        <input type="radio" class="uniform-check-input" name="test_types[]" value="Leak Test of Vac. Elements using MSLD" id="tt_msld" <?php echo $msld_checked; ?>
               onchange="uhvToggleTestForm()">
        Leak Test of Vac. Elements using MSLD
      </label>
      <label class="uniform-check-label">
        <input type="radio" class="uniform-check-input" name="test_types[]" value="Calibration of Vacuum Gauges" id="tt_gauge" <?php echo $gauge_checked; ?>
               onchange="uhvToggleTestForm()">
        Calibration of Vacuum Gauges
      </label>
      <label class="uniform-check-label">
        <input type="radio" class="uniform-check-input" name="test_types[]" value="Bombing & Fine Leak Detection of Electronic Components" id="tt_bombing" <?php echo $bombing_checked; ?>
               onchange="uhvToggleTestForm()">
        Bombing & Fine Leak Detection of Electronic Components
      </label>
    </div>
    <div id="test_type_hint" style="margin-top:8px;font-size:12px;color:#856404;display:none;">
      &#9888; Please select at least one test type
    </div>
    <?php endif; ?>
    <!-- hidden test_type field keeps backward compat for single-type workflows -->
    <input type="hidden" name="test_type" id="test_type_hidden" value="<?php echo esc_attr($d->test_type ?? ''); ?>">
  </td>
</tr>
</table>

<!-- ════════════ MULTIPACTION TEST SUB-FORM ════════════ -->
<div id="form_multipaction_test" style="display:<?php echo $mp_checked ? 'block' : 'none'; ?>;margin-top:20px;">
<div style="background:#f0f0f0;border:2px solid #000;border-radius:6px;padding:0;">
  <div style="background:#000;color:#fff;padding:10px 18px;font-weight:700;font-size:14px;border-radius:4px 4px 0 0;">
    &#9654; Multipaction Test — Test Environment Details
  </div>
  <div style="padding:16px;">
  <table style="width:100%;border-collapse:collapse;">
    <tr>
      <td style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;font-weight:600;width:40%;">Size of package (L x B x H)</td>
      <td style="border:1px solid #000;padding:8px 12px;">
        <div style="display:flex;gap:10px;align-items:center;">
          <input type="number" step="any" min="0" placeholder="L" name="mp_package_l" value="<?php echo esc_attr($mp_size['l']); ?>" <?php echo $ro; ?> style="width:70px;padding:4px;<?php echo $ro_bg; ?>"> mm &times;
          <input type="number" step="any" min="0" placeholder="B" name="mp_package_b" value="<?php echo esc_attr($mp_size['b']); ?>" <?php echo $ro; ?> style="width:70px;padding:4px;<?php echo $ro_bg; ?>"> mm &times;
          <input type="number" step="any" min="0" placeholder="H" name="mp_package_h" value="<?php echo esc_attr($mp_size['h']); ?>" <?php echo $ro; ?> style="width:70px;padding:4px;<?php echo $ro_bg; ?>"> mm
        </div>
      </td>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;font-weight:600;">Attach Test Profile of vacuum requirement &amp; Test duration</td>
      <td style="border:1px solid #000;padding:8px 12px;">
        <select name="mp_test_profile_attach" class="block" <?php echo $ro ? 'disabled' : ''; ?> style="<?php echo $ro_bg; ?>padding:5px;" onchange="uhvToggleMPProfileUpload()">
          <option value="">-- Select --</option>
          <option value="Yes" <?php echo (($d->mp_test_profile_attach??'')==='Yes')?'selected':''; ?>>Yes</option>
          <option value="No" <?php echo (($d->mp_test_profile_attach??'')==='No')?'selected':''; ?>>No</option>
          <option value="Not Applicable" <?php echo (($d->mp_test_profile_attach??'')==='Not Applicable')?'selected':''; ?>>Not Applicable</option>
        </select>
        <div id="mp_profile_upload_div" style="margin-top:10px; display:<?php echo (($d->mp_test_profile_attach??'')==='Yes')?'block':'none'; ?>;">
          <input type="file" name="mp_test_profile_file" accept=".pdf" <?php echo $ro; ?>>
          <?php if (!empty($d->mp_test_profile_file)): ?>
            <div style="margin-top:5px;"><small>Existing: <a href="<?php echo esc_url($d->mp_test_profile_file); ?>" target="_blank">View PDF</a></small></div>
            <input type="hidden" name="mp_test_profile_file_existing" value="<?php echo esc_attr($d->mp_test_profile_file); ?>">
          <?php endif; ?>
        </div>
      </td>
    </tr>
      <td style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;font-weight:600;">No. of "T" type Thermocouples if required</td>
      <td style="border:1px solid #000;padding:8px 12px;"><input type="number" min="0" class="block" name="mp_thermocouples" value="<?php echo esc_attr($d->mp_thermocouples ?? ''); ?>" <?php echo $ro; ?> style="border:1px solid #ccc;padding:5px;background:#fff;<?php echo $ro_bg; ?>"></td>
    <tr>
      <td style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;font-weight:600;">Feedthroughs needed (Qty):</td>
      <td style="border:1px solid #000;padding:8px 12px;">
        <div style="display:flex;gap:15px;align-items:center;margin-bottom:8px;">
          <label style="min-width:80px;font-size:13px;">RF:</label>
          <input type="number" name="mp_ft_rf_qty" value="<?php echo isset($d->mp_ft_rf_qty) ? intval($d->mp_ft_rf_qty) : ''; ?>" min="0" style="width:70px;border:1px solid #ccc;padding:5px;background:#fff;<?php echo $ro_bg; ?>" <?php echo $ro; ?>>
        </div>
        <div style="display:flex;gap:15px;align-items:center;margin-bottom:8px;">
          <label style="min-width:80px;font-size:13px;">Electrical:</label>
          <input type="number" name="mp_ft_elec_qty" value="<?php echo isset($d->mp_ft_elec_qty) ? intval($d->mp_ft_elec_qty) : ''; ?>" min="0" style="width:70px;border:1px solid #ccc;padding:5px;background:#fff;<?php echo $ro_bg; ?>" <?php echo $ro; ?>>
        </div>
        <div style="display:flex;gap:15px;align-items:center;">
          <label style="min-width:80px;font-size:13px;">Others:</label>
          <input type="text" name="mp_ft_others_spec" value="<?php echo esc_attr($d->mp_ft_others_spec ?? ''); ?>" placeholder="Specify..." style="flex:1;border:1px solid #ccc;padding:5px;background:#fff;<?php echo $ro_bg; ?>" <?php echo $ro; ?>>
          <input type="number" name="mp_ft_others_qty" value="<?php echo isset($d->mp_ft_others_qty) ? intval($d->mp_ft_others_qty) : ''; ?>" min="0" style="width:70px;border:1px solid #ccc;padding:5px;background:#fff;<?php echo $ro_bg; ?>" <?php echo $ro; ?>>
        </div>
      </td>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;font-weight:600;vertical-align:top;">Special Instructions (if any)</td>
      <td style="border:1px solid #000;padding:8px 12px;">
        <textarea name="mp_special_instructions" id="mp_special_instructions" rows="3" maxlength="1000" style="width:100%;border:1px solid #ccc;padding:6px;font-size:13px;" <?php echo $ro; ?> onkeyup="uhvCountChars(this)"><?php echo esc_textarea($d->mp_special_instructions ?? ''); ?></textarea>
        <div style="font-size:12px; color:#666; margin-top:4px;">Characters: <span id="mp_char_count">0</span> / 1000</div>
      </td>
    </tr>
  </table>
  </div>
</div>
</div>

<!-- ════════════ THERMAL VACUUM CYCLING TEST SUB-FORM ════════════ -->
<div id="form_tvc_test" style="display:<?php echo $tvc_checked ? 'block' : 'none'; ?>;margin-top:20px;">
<div style="background:#f0f0f0;border:2px solid #000;border-radius:6px;padding:0;">
  <div style="background:#000;color:#fff;padding:10px 18px;font-weight:700;font-size:14px;border-radius:4px 4px 0 0;">
    &#9654; Thermal Vacuum Cycling Test — Test Environment Details
  </div>
  <div style="padding:16px;">
  <table style="width:100%;border-collapse:collapse;">
<?php
  $tvc_size = json_decode($d->tvc_package_size ?? '{}', true) ?: ['l'=>'','b'=>'','h'=>''];
  $tvc_vac  = json_decode($d->tvc_vacuum_range ?? '{}', true) ?: ['mantissa'=>'','exponent'=>''];
?>
    <tr>
      <td style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;font-weight:600;">Size of package (L x B x H) in mm</td>
      <td style="border:1px solid #000;padding:8px 12px;">
        <div style="display:flex;gap:10px;align-items:center;">
          <input type="number" step="any" min="0" placeholder="L" name="tvc_package_l" value="<?php echo esc_attr($tvc_size['l']); ?>" <?php echo $ro; ?> style="width:70px;padding:4px;<?php echo $ro_bg; ?>"> mm &times;
          <input type="number" step="any" min="0" placeholder="B" name="tvc_package_b" value="<?php echo esc_attr($tvc_size['b']); ?>" <?php echo $ro; ?> style="width:70px;padding:4px;<?php echo $ro_bg; ?>"> mm &times;
          <input type="number" step="any" min="0" placeholder="H" name="tvc_package_h" value="<?php echo esc_attr($tvc_size['h']); ?>" <?php echo $ro; ?> style="width:70px;padding:4px;<?php echo $ro_bg; ?>"> mm
        </div>
      </td>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;font-weight:600;">Vacuum Range needed</td>
      <td style="border:1px solid #000;padding:8px 12px;">
        <div style="display:flex;align-items:center;gap:10px;">
          <input type="number" step="any" name="tvc_vacuum_mantissa" placeholder="e.g. 1" value="<?php echo esc_attr($tvc_vac['mantissa']); ?>" style="width:80px;padding:4px;<?php echo $ro_bg; ?>" <?php echo $ro; ?>>
          <strong>&times; 10</strong>
          <input type="number" step="1" name="tvc_vacuum_exponent" placeholder="-5" value="<?php echo esc_attr($tvc_vac['exponent']); ?>" style="width:60px;padding:4px;<?php echo $ro_bg; ?>" <?php echo $ro; ?>>
          <span>mBar</span>
        </div>
      </td>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;font-weight:600;vertical-align:top;">Temperature needed</td>
      <td style="border:1px solid #000;padding:8px 12px;">
        <div style="display:flex;align-items:center;gap:15px;margin-bottom:8px;">
          <label style="min-width:100px;">Hot Cycle:</label>
          <input type="number" step="any" name="tvc_temp_hot" placeholder="Temp" value="<?php echo esc_attr($d->tvc_temp_hot ?? ''); ?>" style="width:80px;padding:4px;<?php echo $ro_bg; ?>" <?php echo $ro; ?>>
          &plusmn; 
          <input type="number" step="any" name="tvc_temp_hot_tol" placeholder="Tol" value="<?php echo esc_attr($d->tvc_temp_hot_tol ?? ''); ?>" style="width:60px;padding:4px;<?php echo $ro_bg; ?>" <?php echo $ro; ?>> °C
        </div>
        <div style="display:flex;align-items:center;gap:15px;">
          <label style="min-width:100px;">Cold Cycle:</label>
          <input type="number" step="any" name="tvc_temp_cold" placeholder="Temp" value="<?php echo esc_attr($d->tvc_temp_cold ?? ''); ?>" style="width:80px;padding:4px;<?php echo $ro_bg; ?>" <?php echo $ro; ?>>
          &plusmn; 
          <input type="number" step="any" name="tvc_temp_cold_tol" placeholder="Tol" value="<?php echo esc_attr($d->tvc_temp_cold_tol ?? ''); ?>" style="width:60px;padding:4px;<?php echo $ro_bg; ?>" <?php echo $ro; ?>> °C
        </div>
      </td>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;font-weight:600;vertical-align:top;">Duration (hours)</td>
      <td style="border:1px solid #000;padding:8px 12px;">
        <div style="display:flex;align-items:center;gap:15px;margin-bottom:8px;">
          <label style="min-width:100px;">Hot Cycle:</label>
          <input type="number" step="any" min="0" name="tvc_duration_hot" value="<?php echo esc_attr($d->tvc_duration_hot ?? ''); ?>" style="width:100px;padding:4px;<?php echo $ro_bg; ?>" <?php echo $ro; ?>>
        </div>
        <div style="display:flex;align-items:center;gap:15px;">
          <label style="min-width:100px;">Cold Cycle:</label>
          <input type="number" step="any" min="0" name="tvc_duration_cold" value="<?php echo esc_attr($d->tvc_duration_cold ?? ''); ?>" style="width:100px;padding:4px;<?php echo $ro_bg; ?>" <?php echo $ro; ?>>
        </div>
      </td>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;font-weight:600;">No. of cycles required</td>
      <td style="border:1px solid #000;padding:8px 12px;"><input type="number" min="0" class="block" name="tvc_cycles_required" value="<?php echo esc_attr($d->tvc_cycles_required ?? ''); ?>" <?php echo $ro; ?> style="border:1px solid #ccc;padding:5px;background:#fff;<?php echo $ro_bg; ?>"></td>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;font-weight:600;">Cycling to be started with</td>
      <td style="border:1px solid #000;padding:8px 12px;">
        <input type="hidden" name="tvc_start_cycle" id="tvc_start_cycle_val" value="<?php echo esc_attr($d->tvc_start_cycle ?? 'Hot'); ?>">
        <label class="uniform-check-label"><input type="checkbox" class="uniform-check-input" id="tvc_start_hot" <?php echo (($d->tvc_start_cycle??'Hot')==='Hot')?'checked':''; ?> <?php echo $resubmit?'disabled':''; ?> onchange="uhvToggleCb(this,'tvc_start_cold','tvc_start_cycle_val','Hot')"> Hot cycle</label>
        <label class="uniform-check-label"><input type="checkbox" class="uniform-check-input" id="tvc_start_cold" <?php echo (($d->tvc_start_cycle??'')==='Cold')?'checked':''; ?> <?php echo $resubmit?'disabled':''; ?> onchange="uhvToggleCb(this,'tvc_start_hot','tvc_start_cycle_val','Cold')"> Cold cycle</label>
      </td>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;font-weight:600;">No. of "T" type Thermocouples mounted</td>
      <td style="border:1px solid #000;padding:8px 12px;"><input type="number" min="0" class="block" name="tvc_thermocouples" value="<?php echo esc_attr($d->tvc_thermocouples ?? ''); ?>" <?php echo $ro; ?> style="border:1px solid #ccc;padding:5px;background:#fff;<?php echo $ro_bg; ?>"></td>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;font-weight:600;vertical-align:top;">Instructions (if any)</td>
      <td style="border:1px solid #000;padding:8px 12px;">
        <textarea name="tvc_instructions" id="tvc_instructions" rows="3" maxlength="1000" style="width:100%;border:1px solid #ccc;padding:6px;font-size:13px;" <?php echo $ro; ?> onkeyup="uhvCountChars(this)"><?php echo esc_textarea($d->tvc_instructions ?? ''); ?></textarea>
        <div style="font-size:12px; color:#666; margin-top:4px;">Characters: <span id="tvc_instruction_count">0</span> / 1000</div>
      </td>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;font-weight:600;vertical-align:top;">Other Tests</td>
      <td style="border:1px solid #000;padding:8px 12px;">
        <textarea name="tvc_other_tests" id="tvc_other_tests" rows="3" maxlength="1000" placeholder="Other Tests: (Attach details)" style="width:100%;border:1px solid #ccc;padding:6px;font-size:13px;" <?php echo $ro; ?> onkeyup="uhvCountChars(this)"><?php echo esc_textarea($d->tvc_other_tests ?? ''); ?></textarea>
        <div style="font-size:12px; color:#666; margin-top:4px;">Characters: <span id="tvc_other_count">0</span> / 1000</div>
      </td>
    </tr>
  </table>
  </div>
</div>
</div>

<!-- ════════════ VCM (OUTGASSING) TESTING SUB-FORM ════════════ -->
<div id="form_vcm_test" style="display:<?php echo $vcm_checked ? 'block' : 'none'; ?>;margin-top:20px;">
<div style="background:#f0f0f0;border:2px solid #000;border-radius:6px;padding:0;">
  <div style="background:#000;color:#fff;padding:10px 18px;font-weight:700;font-size:14px;border-radius:4px 4px 0 0;">
    &#9654; VCM (Outgassing) Testing Material — Test Configuration
  </div>
  <div style="padding:16px;">
  <table style="width:100%;border-collapse:collapse;margin-bottom:15px;background:#fff;" id="vcm_samples_table">
    <thead>
      <tr>
        <th style="border:1px solid #000;padding:8px;background:#ddd;width:50px;">Sl. No.</th>
        <th style="border:1px solid #000;padding:8px;background:#ddd;">Description</th>
        <th style="border:1px solid #000;padding:8px;background:#ddd;">Sample No.</th>
        <th style="border:1px solid #000;padding:8px;background:#ddd;width:80px;">Qty</th>
        <th style="border:1px solid #000;padding:8px;background:#ddd;">Others (Specify)</th>
      </tr>
    </thead>
    <tbody>
      <?php 
      $vcm_samples = json_decode($d->vcm_samples_json ?? '[]', true);
      for($i=1; $i<=12; $i++): 
        $s = $vcm_samples[$i-1] ?? ['desc'=>'','sample'=>'','qty'=>'','others'=>''];
      ?>
      <tr>
        <td style="border:1px solid #000;padding:5px;text-align:center;"><?php echo $i; ?>.</td>
        <td style="border:1px solid #000;padding:2px;"><input type="text" name="vcm_desc[]" value="<?php echo esc_attr($s['desc']??''); ?>" style="width:100%;border:none;padding:5px;" <?php echo $ro; ?>></td>
        <td style="border:1px solid #000;padding:2px;"><input type="text" name="vcm_sample[]" value="<?php echo esc_attr($s['sample']??''); ?>" style="width:100%;border:none;padding:5px;" <?php echo $ro; ?>></td>
        <td style="border:1px solid #000;padding:2px;"><input type="number" step="any" name="vcm_qty[]" value="<?php echo esc_attr($s['qty']??''); ?>" style="width:100%;border:1px solid #ccc;padding:5px;background:#fff;<?php echo $ro_bg; ?>" <?php echo $ro; ?>></td>
        <td style="border:1px solid #000;padding:2px;"><input type="text" name="vcm_others[]" value="<?php echo esc_attr($s['others']??''); ?>" style="width:100%;border:none;padding:5px;" <?php echo $ro; ?>></td>
      </tr>
      <?php endfor; ?>
    </tbody>
  </table>

  <?php 
    $vcm_vac = json_decode($d->vcm_vacuum_req ?? '{}', true);
  ?>
  <table style="width:100%;border-collapse:collapse;background:#fff;">
    <tr>
      <td style="border:1px solid #000;padding:10px;background:#f9f9f9;font-weight:600;width:25%;">Vacuum Requirement:</td>
      <td style="border:1px solid #000;padding:10px;">
        <div style="display:flex;align-items:center;gap:5px;">
          <input type="number" step="any" name="vcm_vacuum_mantissa" value="<?php echo esc_attr($vcm_vac['mantissa'] ?? ''); ?>" placeholder="e.g. 1" style="width:60px;padding:4px;" <?php echo $ro; ?>>
          <span style="font-weight:700;">X 10</span>
          <input type="number" step="1" name="vcm_vacuum_exponent" value="<?php echo esc_attr($vcm_vac['exponent'] ?? ''); ?>" placeholder="-5" style="width:40px;padding:4px;" <?php echo $ro; ?>>
          <span>mBar</span>
        </div>
      </td>
      <td style="border:1px solid #000;padding:10px;background:#f9f9f9;font-weight:600;width:25%;">Temperature Requirement:</td>
      <td style="border:1px solid #000;padding:10px;"></td>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:10px;background:#f9f9f9;font-weight:600;">Duration (hours):</td>
      <td style="border:1px solid #000;padding:10px;"><input type="number" step="any" class="block" name="vcm_duration" value="<?php echo esc_attr($d->vcm_duration ?? ''); ?>" <?php echo $ro; ?>></td>
      <td style="border:1px solid #000;padding:10px;background:#f9f9f9;font-weight:600;">a. Hot Sample bar:</td>
      <td style="border:1px solid #000;padding:10px;">
        <div style="display:flex;align-items:center;gap:5px;">
          <input type="number" step="any" inputmode="decimal" name="vcm_temp_hot_bar" value="<?php echo esc_attr($d->vcm_temp_hot_bar ?? ''); ?>" placeholder="Temp" style="width:60%;padding:4px;" <?php echo $ro; ?>>
          <span>&plusmn;</span>
          <input type="number" step="any" inputmode="decimal" name="vcm_temp_hot_bar_tol" value="<?php echo esc_attr($d->vcm_temp_hot_bar_tol ?? ''); ?>" placeholder="Tol" style="width:30%;padding:4px;" <?php echo $ro; ?>>
          <span>&deg;C</span>
        </div>
      </td>
    </tr>
    <tr>
      <td style="border:1px solid #000;padding:10px;background:#f9f9f9;font-weight:600;">No. of samples loaded:</td>
      <td style="border:1px solid #000;padding:10px;"><input type="number" class="block" name="vcm_samples_loaded" value="<?php echo esc_attr($d->vcm_samples_loaded ?? ''); ?>" <?php echo $ro; ?> style="border:1px solid #ccc;padding:5px;background:#fff;<?php echo $ro_bg; ?>"></td>
      <td style="border:1px solid #000;padding:10px;background:#f9f9f9;font-weight:600;">b. Cold Collector plate bar:</td>
      <td style="border:1px solid #000;padding:10px;">
        <div style="display:flex;align-items:center;gap:5px;">
          <input type="number" step="any" inputmode="decimal" name="vcm_temp_cold_bar" value="<?php echo esc_attr($d->vcm_temp_cold_bar ?? ''); ?>" placeholder="Temp" style="width:60%;padding:4px;" <?php echo $ro; ?>>
          <span>&plusmn;</span>
          <input type="number" step="any" inputmode="decimal" name="vcm_temp_cold_bar_tol" value="<?php echo esc_attr($d->vcm_temp_cold_bar_tol ?? ''); ?>" placeholder="Tol" style="width:30%;padding:4px;" <?php echo $ro; ?>>
          <span>&deg;C</span>
        </div>
      </td>
    </tr>
  </table>
  </div>
</div>
</div>

<!-- ════════════ LEAK TEST (MSLD) SUB-FORM ════════════ -->
<div id="form_msld_test" style="display:<?php echo $msld_checked ? 'block' : 'none'; ?>;margin-top:20px;">
<div style="background:#f0f0f0;border:2px solid #000;border-radius:6px;padding:0;">
  <div style="background:#000;color:#fff;padding:10px 18px;font-weight:700;font-size:14px;border-radius:4px 4px 0 0;">
    &#9654; Leak Test of Vac. Elements using MSLD — Test Details
  </div>
  <div style="padding:16px;">
  <table style="width:100%;border-collapse:collapse;margin-bottom:15px;background:#fff;" id="msld_samples_table">
    <thead>
      <tr>
        <th style="border:1px solid #000;padding:8px;background:#f5f5f5;width:50px;">Sl. No.</th>
        <th style="border:1px solid #000;padding:8px;background:#f5f5f5;">Description</th>
        <th style="border:1px solid #000;padding:8px;background:#f5f5f5;width:120px;">Qty</th>
        <th style="border:1px solid #000;padding:8px;background:#f5f5f5;">Remarks</th>
      </tr>
    </thead>
    <tbody>
      <?php 
      $msld_rows = [
          "Heat pipe / HMC cases",
          "Thermal / Electrical / RF / Thermocouple feedthrough",
          "Shrouds / Bellows",
          "Vacuum lines & fittings",
          "Wave guides / Valves / Gauges",
          "Others"
      ];
      $msld_samples = json_decode($d->msld_samples_json ?? '[]', true);
      foreach($msld_rows as $idx => $desc): 
        $s = $msld_samples[$idx] ?? ['qty'=>'','remarks'=>'','others_spec'=>''];
      ?>
      <tr>
        <td style="border:1px solid #000;padding:5px;text-align:center;"><?php echo $idx+1; ?>.</td>
        <td style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;font-weight:600;">
          <?php echo $desc; ?>
          <?php if($desc === "Others"): ?>
            <br><input type="text" name="msld_others_spec" value="<?php echo esc_attr($s['others_spec']??''); ?>" placeholder="Specify..." style="width:100%; border: 1px solid #ccc; padding: 5px; background: #fff; margin-top: 5px; font-weight: normal; box-sizing: border-box;" <?php echo $ro; ?>>
          <?php endif; ?>
        </td>
        <td style="border:1px solid #000;padding:2px;"><input type="number" step="any" name="msld_qty[]" value="<?php echo esc_attr($s['qty']??''); ?>" style="width:100%;border:1px solid #ccc;padding:5px;background:#fff;<?php echo $ro_bg; ?>" <?php echo $ro; ?>></td>
        <td style="border:1px solid #000;padding:2px;"><input type="text" name="msld_remarks[]" value="<?php echo esc_attr($s['remarks']??''); ?>" style="width:100%;border:1px solid #ccc;padding:5px;background:#fff;<?php echo $ro_bg; ?>" <?php echo $ro; ?>></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
</div>

<!-- ════════════ CALIBRATION OF VACUUM GAUGES SUB-FORM ════════════ -->
<div id="form_gauge_calibration" style="display:<?php echo $gauge_checked ? 'block' : 'none'; ?>;margin-top:20px;">
<div style="background:#f0f0f0;border:2px solid #000;border-radius:6px;padding:0;">
  <div style="background:#000;color:#fff;padding:10px 18px;font-weight:700;font-size:14px;border-radius:4px 4px 0 0;">
    &#9654; I. CALIBRATION OF VACUUM GAUGES
  </div>
  <div style="padding:16px;">
    <p style="font-weight:700;margin-bottom:10px;">Details of gauge to be calibrated:</p>
    <table style="width:100%;border-collapse:collapse;margin-bottom:20px;background:#fff;">
      <thead>
        <tr>
          <th style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;width:80px;">Gauge No.</th>
          <th style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;">Make</th>
          <th style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;">Model</th>
          <th style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;">Sl. No.</th>
          <th style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;">Range</th>
        </tr>
      </thead>
      <tbody>
        <?php 
        $gauge_data = json_decode($d->gauge_calibration_json ?? '[]', true);
        $gauge_rows_input = $gauge_data['gauges'] ?? array_fill(0, 4, ['make'=>'','model'=>'','slno'=>'','range'=>'']);
        for($i=0; $i<4; $i++): 
          $s = $gauge_rows_input[$i] ?? ['make'=>'','model'=>'','slno'=>'','range'=>''];
        ?>
        <tr>
          <td style="border:1px solid #000;padding:5px;text-align:center;"><?php echo $i+1; ?>.</td>
          <td style="border:1px solid #000;padding:2px;"><input type="text" name="gauge_make[]" value="<?php echo esc_attr($s['make']??''); ?>" style="width:100%;border:none;padding:5px;" <?php echo $ro; ?>></td>
          <td style="border:1px solid #000;padding:2px;"><input type="text" name="gauge_model[]" value="<?php echo esc_attr($s['model']??''); ?>" style="width:100%;border:none;padding:5px;" <?php echo $ro; ?>></td>
          <td style="border:1px solid #000;padding:2px;"><input type="text" name="gauge_slno[]" value="<?php echo esc_attr($s['slno']??''); ?>" style="width:100%;border:none;padding:5px;" <?php echo $ro; ?>></td>
          <td style="border:1px solid #000;padding:2px;"><input type="text" name="gauge_range[]" value="<?php echo esc_attr($s['range']??''); ?>" style="width:100%;border:none;padding:5px;" <?php echo $ro; ?>></td>
        </tr>
        <?php endfor; ?>
      </tbody>
    </table>

    <p style="font-weight:700;margin-bottom:10px;">Type of reference Gauge needed:</p>
    <table style="width:100%;border-collapse:collapse;background:#fff;">
      <thead>
        <tr>
          <th style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;width:50px;"></th>
          <th style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;">Name of the Gauge head</th>
          <th style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;">Working Pressure Range</th>
          <th style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;">Details / Remarks</th>
        </tr>
      </thead>
      <tbody>
        <?php 
        $ref_gauges = [
          "Spinning Rotor Gauge" => "1x 10⁻² mbar to 1x 10⁻⁷ mbar.",
          "Capacitance Manometer" => "1000 torr / 1 torr / 0.05 torr",
          "BA - Ion Gauge" => "1e-4mbar to 1e-10mbar"
        ];
        $selected_refs = $gauge_data['refs'] ?? []; // This was array of names
        $refs_full = $gauge_data['refs_full'] ?? []; // New structure: name => remark
        
        $idx = 0;
        foreach($ref_gauges as $name => $range):
          // Backward compat or new structure
          $checked = in_array($name, $selected_refs) || isset($refs_full[$name]) ? 'checked' : '';
          $remark_val = $refs_full[$name] ?? '';
        ?>
        <tr>
          <td style="border:1px solid #000;padding:5px;text-align:center;">
            <input type="checkbox" name="gauge_refs[]" value="<?php echo esc_attr($name); ?>" <?php echo $checked; ?> <?php echo $resubmit?'disabled':''; ?>>
          </td>
          <td style="border:1px solid #000;padding:8px;font-weight:600;"><?php echo $name; ?></td>
          <td style="border:1px solid #000;padding:8px;background:#f9f9f9;"><?php echo $range; ?></td>
          <td style="border:1px solid #000;padding:2px;">
            <input type="text" name="gauge_refs_remarks[<?php echo esc_attr($name); ?>]" value="<?php echo esc_attr($remark_val); ?>" style="width:100%;border:none;padding:5px;" placeholder="Details..." <?php echo $ro; ?>>
          </td>
        </tr>
        <?php $idx++; endforeach; ?>
      </tbody>
    </table>
    
    <br>
    <!-- Paper Style Headers: Underlined, Bold -->
    <p style="text-decoration:underline; font-weight:700; font-size:16px; margin-top:20px; margin-bottom:15px;">II. CORONA / HIGH ALTITUDE TEST</p>
    
    <table style="width:100%;border-collapse:collapse;background:#fff;margin-bottom:20px;">
      <thead>
        <tr>
          <th style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;width:50px;">Sl. No.</th>
          <th style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;">Details of feedthroughs needed</th>
          <th style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;width:80px;">Qty</th>
          <th style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;">Vacuum requirement</th>
          <th style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;">Duration</th>
          <th style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;">Remarks</th>
        </tr>
      </thead>
      <tbody>
        <?php 
        $corona_samples = json_decode($d->corona_test_json ?? '[]', true);
        for($i=1; $i<=3; $i++): 
          $s = $corona_samples[$i-1] ?? ['desc'=>'','qty'=>'','vacuum'=>'','duration'=>'','remarks'=>''];
        ?>
        <tr>
          <td style="border:1px solid #000;padding:5px;text-align:center;"><?php echo $i; ?>.</td>
          <td style="border:1px solid #000;padding:2px;"><input type="text" name="corona_desc[]" value="<?php echo esc_attr($s['desc']??''); ?>" style="width:100%;border:none;padding:5px;" <?php echo $ro; ?>></td>
          <td style="border:1px solid #000;padding:2px;"><input type="number" step="any" name="corona_qty[]" value="<?php echo esc_attr($s['qty']??''); ?>" style="width:100%;border:1px solid #ccc;padding:5px;background:#fff;<?php echo $ro_bg; ?>" <?php echo $ro; ?>></td>
          <td style="border:1px solid #000;padding:2px;"><input type="text" name="corona_vacuum[]" value="<?php echo esc_attr($s['vacuum']??''); ?>" style="width:100%;border:none;padding:5px;" <?php echo $ro; ?>></td>
          <td style="border:1px solid #000;padding:2px;"><input type="text" name="corona_duration[]" value="<?php echo esc_attr($s['duration']??''); ?>" style="width:100%;border:none;padding:5px;" <?php echo $ro; ?>></td>
          <td style="border:1px solid #000;padding:2px;"><input type="text" name="corona_remarks[]" value="<?php echo esc_attr($s['remarks']??''); ?>" style="width:100%;border:none;padding:5px;" <?php echo $ro; ?>></td>
        </tr>
        <?php endfor; ?>
      </tbody>
    </table>

    <br>
    <p style="text-decoration:underline; font-weight:700; font-size:16px; margin-top:10px; margin-bottom:15px;">III. OTHER SPECIAL TEST (Describe):</p>
    <div style="border:1px solid #000; padding:10px; background:#fff;">
      <textarea name="other_special_test_desc" rows="5" placeholder="Enter description of special test here..." style="width:100%;border:none;padding:10px;font-size:14px;" <?php echo $ro; ?>><?php echo esc_textarea($d->other_special_test_desc ?? ''); ?></textarea>
    </div>
  </div>
</div>
</div>


<!-- ════════════ BOMBING & FINE LEAK DETECTION SUB-FORM ════════════ -->
<div id="form_bombing_leak_test" style="display:<?php echo $bombing_checked ? 'block' : 'none'; ?>;margin-top:20px;">
<div style="background:#f0f0f0;border:2px solid #000;border-radius:6px;padding:0;">
  <div style="background:#000;color:#fff;padding:10px 18px;font-weight:700;font-size:14px;border-radius:4px 4px 0 0;">
    &#9654; Bombing & Fine Leak Detection of Electronic Components — Test Details
  </div>
  <div style="padding:16px;">
    <p style="font-weight:700;margin-bottom:10px;">Test Details for Bombing:</p>
    <table style="width:100%;border-collapse:collapse;background:#fff;">
      <thead>
        <tr>
          <th style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;width:50px;">Sl. No.</th>
          <th style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;">Name of the Components</th>
          <th style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;width:80px;">Qty</th>
          <th style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;">Pressure in PSI</th>
          <th style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;">Dwell time</th>
          <th style="border:1px solid #000;padding:8px 12px;background:#f5f5f5;">Permissible leak test Rate for Fine Leak Test</th>
        </tr>
      </thead>
      <tbody>
        <?php 
        $bombing_samples = json_decode($d->bombing_leak_test_json ?? '[]', true);
        for($i=1; $i<=5; $i++): 
          $s = $bombing_samples[$i-1] ?? ['name'=>'','qty'=>'','pressure'=>'','dwell'=>'','rate'=>''];
        ?>
        <tr>
          <td style="border:1px solid #000;padding:5px;text-align:center;"><?php echo $i; ?>.</td>
          <td style="border:1px solid #000;padding:2px;"><input type="text" name="bombing_name[]" value="<?php echo esc_attr($s['name']??''); ?>" style="width:100%;border:none;padding:5px;" <?php echo $ro; ?>></td>
          <td style="border:1px solid #000;padding:2px;"><input type="number" step="any" name="bombing_qty[]" value="<?php echo esc_attr($s['qty']??''); ?>" style="width:100%;border:1px solid #ccc;padding:5px;background:#fff;<?php echo $ro_bg; ?>" <?php echo $ro; ?>></td>
          <td style="border:1px solid #000;padding:2px;"><input type="text" name="bombing_pressure[]" value="<?php echo esc_attr($s['pressure']??''); ?>" style="width:100%;border:none;padding:5px;" <?php echo $ro; ?>></td>
          <td style="border:1px solid #000;padding:2px;"><input type="text" name="bombing_dwell[]" value="<?php echo esc_attr($s['dwell']??''); ?>" style="width:100%;border:none;padding:5px;" <?php echo $ro; ?>></td>
          <td style="border:1px solid #000;padding:2px;"><input type="text" name="bombing_rate[]" value="<?php echo esc_attr($s['rate']??''); ?>" style="width:100%;border:none;padding:5px;" <?php echo $ro; ?>></td>
        </tr>
        <?php endfor; ?>
      </tbody>
    </table>
  </div>
</div>
</div>

<!-- Dead code removed (form_tvp_test) -->
<div style="text-align:right;margin-top:30px;display:flex;justify-content:flex-end;gap:15px;flex-wrap:wrap;">
  <?php if (!$resubmit): ?>
  <button type="submit" name="save_indenter_draft" class="btn btn-draft" style="padding:14px 30px;font-size:18px;">&#128190; SAVE DRAFT</button>
  <?php endif; ?>
  <button type="submit" name="submit_request" class="btn-submit" style="padding:14px 40px;font-size:18px;font-weight:700;">
    <?php echo ($resubmit || $qa_rejected_edit) ? '&#8617; RESUBMIT FOR APPROVAL' : 'SUBMIT FOR APPROVAL'; ?>
  </button>
</div>
</form>

<script>
function uhvToggleTestForm() {
    var mp   = document.getElementById('tt_mp');
    var tvc  = document.getElementById('tt_tvc');
    var vcm  = document.getElementById('tt_vcm');
    var msld = document.getElementById('tt_msld');
    var gauge = document.getElementById('tt_gauge');
    var bombing = document.getElementById('tt_bombing');

    var fmp  = document.getElementById('form_multipaction_test');
    var ftvc = document.getElementById('form_tvc_test');
    var fvcm = document.getElementById('form_vcm_test');
    var fmsld = document.getElementById('form_msld_test');
    var fgauge = document.getElementById('form_gauge_calibration');
    var fbombing = document.getElementById('form_bombing_leak_test');

    var hint = document.getElementById('test_type_hint');
    var hidden = document.getElementById('test_type_hidden');

    var mp_checked   = mp   && mp.checked;
    var tvc_checked  = tvc  && tvc.checked;
    var vcm_checked  = vcm  && vcm.checked;
    var msld_checked = msld && msld.checked;
    var gauge_checked = gauge && gauge.checked;
    var bombing_checked = bombing && bombing.checked;
    
    var any_checked  = mp_checked || tvc_checked || vcm_checked || msld_checked || gauge_checked || bombing_checked;

    // Show/hide sub-forms
    if (fmp)   fmp.style.display   = mp_checked   ? 'block' : 'none';
    if (ftvc)  ftvc.style.display  = tvc_checked  ? 'block' : 'none';
    if (fvcm)  fvcm.style.display  = vcm_checked  ? 'block' : 'none';
    if (fmsld) fmsld.style.display = msld_checked ? 'block' : 'none';
    if (fgauge) fgauge.style.display = gauge_checked ? 'block' : 'none';
    if (fbombing) fbombing.style.display = bombing_checked ? 'block' : 'none';

    // Update hidden test_type for backward compat
    var types = [];
    if (mp_checked)   types.push('Multipaction Test');
    if (tvc_checked)  types.push('Thermal Vacuum Cycling Test');
    if (vcm_checked)  types.push('VCM (Outgassing) Testing Material');
    if (msld_checked) types.push('Leak Test of Vac. Elements using MSLD');
    if (gauge_checked) types.push('Calibration of Vacuum Gauges');
    if (bombing_checked) types.push('Bombing & Fine Leak Detection of Electronic Components');
    
    // Add corona and other if they exist (for future-proofing)
    var corona = document.getElementById('tt_corona');
    var other = document.getElementById('tt_other');
    if (corona && corona.checked) types.push('Corona / High Altitude Test');
    if (other && other.checked)   types.push('Other Special Test');
    
    if (hidden) hidden.value = types.join(', ');

    // Show hint if nothing selected
    if (hint) hint.style.display = types.length === 0 ? 'block' : 'none';
}
// Run once on load to restore state
document.addEventListener('DOMContentLoaded', function() { 
    uhvToggleTestForm(); 
    if (typeof toggleQA === 'function') toggleQA(); 
    if (document.getElementById('mp_special_instructions')) uhvCountChars(document.getElementById('mp_special_instructions'));
    if (document.getElementById('tvc_instructions')) uhvCountChars(document.getElementById('tvc_instructions'));
    if (document.getElementById('tvc_other_tests')) uhvCountChars(document.getElementById('tvc_other_tests'));
});
function uhvCountChars(el) {
    var id = el.id;
    var counterId = '';
    if (id === 'mp_special_instructions') counterId = 'mp_char_count';
    else if (id === 'tvc_instructions') counterId = 'tvc_instruction_count';
    else if (id === 'tvc_other_tests') counterId = 'tvc_other_count';
    
    if (counterId) {
        var counter = document.getElementById(counterId);
        if (counter) counter.innerText = el.value.length;
    }
}
function uhvToggleMPProfileUpload() {
    var sel = document.querySelector('select[name="mp_test_profile_attach"]');
    var div = document.getElementById('mp_profile_upload_div');
    if (sel && div) {
        div.style.display = (sel.value === 'Yes') ? 'block' : 'none';
    }
}
<?php if (!$resubmit): // available for new, draft, and qa_rejected_edit ?>
function toggleQA() {
    const qaFields = document.querySelectorAll('.qa_field');
    const qaSearch = document.getElementById('qa_search');
    const hasQA = document.getElementById('qa_exists_val').value.toLowerCase() === 'yes';
    qaFields.forEach(function(f){ f.style.display = hasQA ? 'table-cell' : 'none'; });
    qaSearch.style.display = hasQA ? 'block' : 'none';
    if (!hasQA) {
        ['qa_name','qa_stno','qa_email','qa_designation','qa_section','qa_phone'].forEach(function(id){
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
            document.getElementById('qa_name').value        = data.data.name || '';
            document.getElementById('qa_stno').value        = data.data.stno || '';
            document.getElementById('qa_email').value       = data.data.email || '';
            document.getElementById('qa_designation').value = data.data.desgn || '';
            document.getElementById('qa_section').value     = data.data.sectionfullname || '';
            document.getElementById('qa_phone').value       = data.data.telephoneno || '';
        } else {
            alert('Employee not found! Please check the Staff Number.');
        }
    })
    .catch(function(){ alert('Error fetching employee data. Please try again.'); });
}

// Staff Execution Duration Calculator
jQuery(document).ready(function($) {
    function calculateDuration() {
        var startVal = $('input[name="test_started_datetime"]').val();
        var endVal = $('input[name="test_completed_datetime"]').val();
        if (startVal && endVal) {
            var start = new Date(startVal);
            var end = new Date(endVal);
            var diff = end - start;
            if (diff >= 0) {
                var totalSeconds = Math.floor(diff / 1000);
                var hours = Math.floor(totalSeconds / 3600);
                var minutes = Math.floor((totalSeconds % 3600) / 60);
                var seconds = totalSeconds % 60;
                var display = [hours, minutes, seconds].map(v => v < 10 ? "0" + v : v).join(":");
                $('input[name="test_duration"]').val(display);
            } else {
                $('input[name="test_duration"]').val("Invalid Range");
            }
        }
    }
    $('input[name="test_started_datetime"], input[name="test_completed_datetime"]').on('change input', calculateDuration);
});

// MSLD Submission Confirmation Logic
var msldConfirmed = false;
document.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('uhv_request_form');
    if (form) {
        form.addEventListener('submit', function(e) {
            // Only intercept for the actual submission, not draft saving
            var submitter = e.submitter || document.activeElement;
            if (submitter && submitter.getAttribute('name') === 'save_indenter_draft') {
                return; // Let draft save proceed
            }

            var msld = document.getElementById('tt_msld');
            if (msld && msld.checked && !msldConfirmed) {
                e.preventDefault();
                document.getElementById('msld_confirm_modal').style.display = 'flex';
            }
        });
    }
});

function closeMSLDModal() {
    document.getElementById('msld_confirm_modal').style.display = 'none';
    msldConfirmed = false; // Reset if they close it
}

function proceedWithMSLDSubmission() {
    if (msldConfirmed) return; // Prevent double trigger
    msldConfirmed = true;
    
    // Disable the button to prevent multiple clicks
    var proceedBtn = document.querySelector('#msld_confirm_modal button[onclick="proceedWithMSLDSubmission()"]');
    if (proceedBtn) {
        proceedBtn.disabled = true;
        proceedBtn.innerText = "Processing...";
        proceedBtn.style.opacity = "0.7";
        proceedBtn.style.cursor = "not-allowed";
    }

    document.getElementById('msld_confirm_modal').style.display = 'none';
    
    // Trigger the actual submit button
    var btn = document.querySelector('button[name="submit_request"]');
    if (btn) btn.click();
}
<?php endif; ?>
</script>

<!-- MSLD Confirmation Modal -->
<div id="msld_confirm_modal" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.7); align-items:center; justify-content:center; backdrop-filter:blur(4px);">
    <div style="background:#fff; border-radius:12px; width:95%; max-width:600px; overflow:hidden; box-shadow:0 20px 50px rgba(0,0,0,0.5); animation:uhvFadeScale 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);">
        <div style="background:#000; color:#fff; padding:18px 25px; font-weight:700; font-size:18px; display:flex; align-items:center; gap:12px;">
            <span style="font-size:24px;">&#9888;</span> Important Note: MSLD Leak Test
        </div>
        <div style="padding:30px; line-height:1.7; font-size:16px; color:#222;">
            <p style="margin-bottom:20px; font-weight:600; color:#000;">Please note the following requirements for MSLD testing:</p>
            <ul style="margin:0; padding-left:22px; list-style-type: none;">
                <li style="margin-bottom:15px; position:relative;">
                    <span style="position:absolute; left:-22px; color:#000;">•</span>
                    Requisition to be given at least <strong>48 Hrs. prior to test</strong>. Test will be conducted subjected to availability of MSLD & priority of test.
                </li>
                <li style="margin-bottom:15px; position:relative;">
                    <span style="position:absolute; left:-22px; color:#000;">•</span>
                    Indentor may arrange suitable test adaptors between test object & MSLD with additional pumping as applicable.
                </li>
                <li style="position:relative;">
                    <span style="position:absolute; left:-22px; color:#000;">•</span>
                    Mention specific date of start and delivery schedule with reason in <strong>Remarks column</strong> if applicable.
                </li>
            </ul>
        </div>
        <div style="background:#f9f9f9; padding:20px 25px; text-align:right; gap:15px; display:flex; justify-content:flex-end; border-top:1px solid #eee;">
            <button type="button" onclick="closeMSLDModal()" style="padding:12px 24px; background:#fff; border:1px solid #ccc; border-radius:6px; cursor:pointer; font-weight:600; font-size:15px; transition:all 0.2s;">Go Back</button>
            <button type="button" onclick="proceedWithMSLDSubmission()" style="padding:12px 30px; background:#000; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:700; font-size:15px; box-shadow:0 4px 10px rgba(0,0,0,0.2); transition:all 0.2s;">I Agree & Proceed</button>
        </div>
    </div>
</div>
<style>
@keyframes uhvFadeScale {
    from { opacity:0; transform:translateY(20px) scale(0.95); }
    to { opacity:1; transform:translateY(0) scale(1); }
}
#msld_confirm_modal button:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 15px rgba(0,0,0,0.15);
}
</style>
<?php }

// =====================================================================
//  SHARED: MANAGER TAB BAR
// =====================================================================
function mgr_tabs($active, $cnt_pending, $cnt_my_qa_pending=0) {
    $base = get_permalink();
    $tabs = [
        ['label'=>'Dashboard',              'href'=>$base,                                                    'key'=>'dashboard'],
        ['label'=>"Pending ($cnt_pending)", 'href'=>add_query_arg('mgr_action','pending',$base),              'key'=>'pending'],
        ['label'=>'In Testing',             'href'=>add_query_arg('mgr_action','in_testing',$base),           'key'=>'in_testing'],
        ['label'=>'Rejected',               'href'=>add_query_arg('mgr_action','rejected_list',$base),        'key'=>'rejected_list'],
        ['label'=>'Completed',              'href'=>add_query_arg('mgr_action','completed_list',$base),       'key'=>'completed_list'],
        ['label'=>'My Requests',            'href'=>add_query_arg('mgr_action','my_requests',$base),          'key'=>'my_requests'],
    ];
    // Show QA Review tab only when this manager is nominated as QA on at least one request
    if ($cnt_my_qa_pending > 0) {
        $tabs[] = ['label'=>"QA Review (" . $cnt_my_qa_pending . " pending)", 'href'=>add_query_arg('mgr_action','qa_review',$base), 'key'=>'qa_review', 'qa'=>true];
    } else {
        // Still show the tab if they have reviewed requests to see history
        $tabs[] = ['label'=>"My QA Reviews", 'href'=>add_query_arg('mgr_action','qa_review',$base), 'key'=>'qa_review', 'qa'=>false];
    }
    echo '<div class="mgr-tabs">';
    foreach($tabs as $t) {
        $cls = ($t['key']===$active) ? 'mgr-tab active' : 'mgr-tab';
        $style = (!empty($t['qa']) && $t['qa']) ? ' style="background:#6f42c1;color:#fff;font-weight:700;"' : '';
        if ($t['key']===$active && !empty($t['qa'])) $style = ' style="background:#4a148c;color:#fff;font-weight:700;"';
        echo "<a href='".esc_url($t['href'])."' class='$cls'$style>".esc_html($t['label'])."</a>";
    }
    $new_req_url = remove_query_arg(['resume_draft', 'view_id', 'prog_id', 'uhv_msg'], add_query_arg('mgr_action', 'create_new', get_permalink()));
    echo "<a href='".esc_url($new_req_url)."' class='mgr-tab mgr-tab-new'>+ NEW REQUEST</a>";
    echo '</div>';
}

// =====================================================================
//  SHARED: STAT CARDS
// =====================================================================
function mgr_stat_cards($cnt_pending, $cnt_qa_review, $cnt_testing, $cnt_rejected, $cnt_completed) {
    $base = get_permalink(); ?>
  <div class="stat-grid" style="margin-bottom:30px;">
    <a href="<?php echo esc_url(add_query_arg('mgr_action','pending',$base)); ?>" class="stat-card sc-pending" style="text-decoration:none;cursor:pointer;">
      <div class="stat-num"><?php echo $cnt_pending; ?></div><div class="stat-lbl">Awaiting My Decision</div>
    </a>
    <a href="<?php echo esc_url(add_query_arg('mgr_action','qa_review',$base)); ?>" class="stat-card" style="border-color:#6f42c1;background:#f3eeff;color:#4a148c;text-decoration:none;cursor:pointer;">
      <div class="stat-num"><?php echo $cnt_qa_review; ?></div><div class="stat-lbl">In QA Review</div>
    </a>
    <a href="<?php echo esc_url(add_query_arg('mgr_action','in_testing',$base)); ?>" class="stat-card sc-approved" style="text-decoration:none;cursor:pointer;">
      <div class="stat-num"><?php echo $cnt_testing; ?></div><div class="stat-lbl">In Testing</div>
    </a>
    <a href="<?php echo esc_url(add_query_arg('mgr_action','rejected_list',$base)); ?>" class="stat-card" style="border-color:#dc3545;background:#fff5f5;color:#721c24;text-decoration:none;cursor:pointer;">
      <div class="stat-num"><?php echo $cnt_rejected; ?></div><div class="stat-lbl">Rejected / Returned</div>
    </a>
    <a href="<?php echo esc_url(add_query_arg('mgr_action','completed_list',$base)); ?>" class="stat-card" style="border-color:#000;background:#f8f8f8;color:#000;text-decoration:none;cursor:pointer;">
      <div class="stat-num"><?php echo $cnt_completed; ?></div><div class="stat-lbl">Completed</div>
    </a>
  </div>
<?php }

// =====================================================================
//  PIPELINE HELPER - GET EXTENDED PIPELINE STEPS
// =====================================================================
// Returns extended pipeline steps for both Indenter and Manager views
// New stages after "Manager Approved": Test Started, Test Completed

function uhv_get_extended_pipeline_steps($req) {
    // Base pipeline: Submitted → [QA Review] → Mgr Approved
    $steps = [
        [
            'label' => 'Submitted',
            'done'  => true,
            'date'  => $req->submission_date,
            'stage' => 'submitted'
        ],
    ];

    if (!empty($req->qa_exists) && strtolower($req->qa_exists) === 'yes') {
        $qa_rejected = ($req->status === 'qa_rejected');
        $steps[] = [
            'label'    => $qa_rejected ? 'QA Rejected' : 'QA Review',
            'done'     => !in_array($req->status, ['draft_indenter', 'pending_qa', 'qa_rejected']),
            'date'     => $req->qa_review_date ?? null,
            'stage'    => 'qa_review',
            'rejected' => $qa_rejected
        ];
    }

    // New Step: Staff Review (Phase 1)
    $staff_rechecked = ($req->status === 'recheck_indenter');
    $steps[] = [
        'label'    => $staff_rechecked ? 'Staff Recheck' : 'Staff Review',
        'done'     => !in_array($req->status, ['pending_staff', 'recheck_staff', 'pending_qa', 'qa_rejected', 'draft_indenter']),
        'date'     => $req->staff_review_date ?? null,
        'stage'    => 'staff_review',
        'rejected' => $staff_rechecked
    ];

    $mgr_rejected = ($req->status === 'rejected');
    $mgr_returned = ($req->status === 'manager_returned');
    $mgr_label    = 'Mgr Approved';
    if ($mgr_rejected) $mgr_label = 'Mgr Rejected';
    if ($mgr_returned) $mgr_label = 'Mgr Returned';

    $steps[] = [
        'label'    => $mgr_label,
        'done'     => in_array($req->status, ['approved', 'completed', 'in_testing']),
        'date'     => ($mgr_returned ? $req->manager_decision_date : ($req->approval_date ?? null)),
        'stage'    => 'mgr_approved',
        'rejected' => ($mgr_rejected || $mgr_returned)
    ];

    // Extended pipeline: Chamber Occupied → Test Started → Test Completed → Chamber Vacated
    if (in_array($req->status, ['approved', 'completed', 'in_testing'])) {
        
        // Chamber Occupied: Assume occupied when test_started_datetime is filled (simulating real-time updates)
        $steps[] = [
            'label' => 'Chamber Occupied',
            'done'  => !empty($req->test_started_datetime) && $req->test_started_datetime !== '0000-00-00 00:00:00',
            'date'  => ($req->test_started_datetime && $req->test_started_datetime !== '0000-00-00 00:00:00') ? $req->test_started_datetime : null,
            'stage' => 'chamber_occupied'
        ];
        
        $steps[] = [
            'label' => 'Test Started',
            'done'  => !empty($req->test_started_datetime) && $req->test_started_datetime !== '0000-00-00 00:00:00',
            'date'  => ($req->test_started_datetime && $req->test_started_datetime !== '0000-00-00 00:00:00') ? $req->test_started_datetime : null,
            'stage' => 'test_started'
        ];
        
        $steps[] = [
            'label' => 'Test Completed',
            'done'  => !empty($req->test_completed_datetime) && $req->test_completed_datetime !== '0000-00-00 00:00:00',
            'date'  => ($req->test_completed_datetime && $req->test_completed_datetime !== '0000-00-00 00:00:00') ? $req->test_completed_datetime : null,
            'stage' => 'test_completed'
        ];
        
        // Chamber Vacated: When status === 'completed'
        $steps[] = [
            'label' => 'Chamber Vacated',
            'done'  => ($req->status === 'completed'),
            'date'  => ($req->status === 'completed') ? $req->completion_date : null,
            'stage' => 'chamber_vacated'
        ];
    }
    
    return $steps;
}

// =====================================================================
//  HELPER: Display test-type specific sub-form data (read-only)
// =====================================================================
function uhv_render_test_subforms_readonly($req) {
    $types = array_filter(array_map('trim', explode(',', $req->test_types ?? '')));
    // Fallback: if test_types empty but test_type exists, treat as TVP/TBT
    if (empty($types) && !empty($req->test_type)) $types = ['Multipaction Test'];
    if (empty($types)) return;

    // Helper: uniform black/grey section box
    $box_open  = '<div style="margin-top:16px;background:#f0f0f0;border:2px solid #000;border-radius:6px;">';
    $box_close = '</div>';
    $hdr_style = 'background:#000;color:#fff;padding:12px 18px;font-weight:700;font-size:17px;border-radius:4px 4px 0 0;letter-spacing:0.5px;';
    $th_style  = 'border:1px solid #000;padding:12px 14px;background:#f5f5f5;width:40%;text-align:left;font-weight:700;font-size:17px;';
    $td_style  = 'border:1px solid #000;padding:12px 14px;font-size:17px;';

    // Multipaction Test
    if (in_array('Multipaction Test', $types)) {
        echo $box_open;
        echo '<div style="'.$hdr_style.'">&#9654; Multipaction Test — Test Requirement Details</div>';
        echo '<div style="padding:12px;"><table style="width:100%;border-collapse:collapse;font-size:17px;">';
        
        $mp_size_disp = '—';
        if (!empty($req->mp_package_size)) {
            $sz = json_decode($req->mp_package_size, true);
            if (is_array($sz)) {
                $mp_size_disp = esc_html($sz['l']??'').' mm &times; '.esc_html($sz['b']??'').' mm &times; '.esc_html($sz['h']??'').' mm';
            } else {
                $mp_size_disp = esc_html($req->mp_package_size);
            }
        }

        $profile_link = esc_html($req->mp_test_profile_attach ?? '');
        if (!empty($req->mp_test_profile_file)) {
            $profile_link .= ' (<a href="'.esc_url($req->mp_test_profile_file).'" target="_blank">View PDF</a>)';
        }

        $mp_fields = [
            'Package Size (L x B x H)'  => $mp_size_disp,
            'Test Profile Attached'     => $profile_link,
            'No. of Thermocouples'      => esc_html($req->mp_thermocouples ?? ''),
            'Feedthrough RF Qty'        => intval($req->mp_ft_rf_qty ?? 0),
            'Feedthrough Elec. Qty'     => intval($req->mp_ft_elec_qty ?? 0),
            'Others (Specify)'          => esc_html($req->mp_ft_others_spec ?? ''),
            'Others Qty'                => intval($req->mp_ft_others_qty ?? 0),
            'Special Instructions'      => nl2br(esc_html($req->mp_special_instructions ?? '')),
        ];
        foreach ($mp_fields as $label => $val) {
            echo '<tr><th style="'.$th_style.'">'.esc_html($label).'</th>';
            echo '<td style="'.$td_style.'">'.($val ?: '—').'</td></tr>';
        }
        echo '</table></div>'.$box_close;
    }

    // Thermal Vacuum Cycling Test
    if (in_array('Thermal Vacuum Cycling Test', $types)) {
        echo $box_open;
        echo '<div style="'.$hdr_style.'">&#9654; Thermal Vacuum Cycling Test — Test Requirement Details</div>';
        echo '<div style="padding:12px;"><table style="width:100%;border-collapse:collapse;font-size:17px;">';
        $tvc_size_disp = '—';
        if (!empty($req->tvc_package_size)) {
            $sz = json_decode($req->tvc_package_size, true);
            if (is_array($sz)) {
                $tvc_size_disp = esc_html($sz['l']??'').' mm &times; '.esc_html($sz['b']??'').' mm &times; '.esc_html($sz['h']??'').' mm';
            } else {
                $tvc_size_disp = esc_html($req->tvc_package_size);
            }
        }

        $tvc_vac_disp = '—';
        if (!empty($req->tvc_vacuum_range)) {
            $vac = json_decode($req->tvc_vacuum_range, true);
            if (is_array($vac)) {
                $tvc_vac_disp = esc_html($vac['mantissa']??'').' &times; 10<sup>'.esc_html($vac['exponent']??'').'</sup> mBar';
            } else {
                $tvc_vac_disp = esc_html($req->tvc_vacuum_range);
            }
        }

        $tvc_fields = [
            'Package Size (L x B x H)'  => $tvc_size_disp,
            'Vacuum Range'              => $tvc_vac_disp,
            'Temperature (Hot)'         => esc_html($req->tvc_temp_hot ?? '').' &deg;C (&plusmn;'.esc_html($req->tvc_temp_hot_tol ?? '').')',
            'Temperature (Cold)'        => esc_html($req->tvc_temp_cold ?? '').' &deg;C (&plusmn;'.esc_html($req->tvc_temp_cold_tol ?? '').')',
            'Duration (Hot) (hours)'    => esc_html($req->tvc_duration_hot ?? ''),
            'Duration (Cold) (hours)'   => esc_html($req->tvc_duration_cold ?? ''),
            'No. of Cycles Required'    => esc_html($req->tvc_cycles_required ?? ''),
            'Start of Cycle (Hot/Cold)' => esc_html($req->tvc_start_cycle ?? ''),
            'No. of Thermocouples'      => esc_html($req->tvc_thermocouples ?? ''),
            'Instructions (if any)'     => nl2br(esc_html($req->tvc_instructions ?? '')),
            'Other Tests'               => nl2br(esc_html($req->tvc_other_tests ?? '')),
        ];
        foreach ($tvc_fields as $label => $val) {
            echo '<tr><th style="'.$th_style.'">'.esc_html($label).'</th>';
            echo '<td style="'.$td_style.'">'.($val ?: '—').'</td></tr>';
        }
        echo '</table></div>'.$box_close;
    }

    // VCM (Outgassing) Testing Material
    if (in_array('VCM (Outgassing) Testing Material', $types)) {
        echo $box_open;
        echo '<div style="'.$hdr_style.'">&#9654; VCM (Outgassing) Testing Material — Test Requirement Details</div>';
        echo '<div style="padding:12px;">';
        
        echo '<table style="width:100%;border-collapse:collapse;font-size:16px;margin-bottom:15px;">';
        echo '<thead><tr><th style="'.$th_style.'">Sl.</th><th style="'.$th_style.'">Description of Material</th><th style="'.$th_style.'">Sample Reference</th><th style="'.$th_style.'">Qty</th><th style="'.$th_style.'">Others</th></tr></thead>';
        echo '<tbody>';
        $vcm_data = json_decode($req->vcm_samples_json ?? '[]', true);
        for($i=0; $i<12; $i++) {
            $row = $vcm_data[$i] ?? null;
            echo '<tr>';
            echo '<td style="'.$td_style.';text-align:center;">'.($i+1).'</td>';
            echo '<td style="'.$td_style.'">'.esc_html($row['desc'] ?? '').'</td>';
            echo '<td style="'.$td_style.'">'.esc_html($row['sample'] ?? '').'</td>';
            echo '<td style="'.$td_style.'">'.esc_html($row['qty'] ?? '').'</td>';
            echo '<td style="'.$td_style.'">'.esc_html($row['others'] ?? '').'</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<table style="width:100%; border-collapse:collapse; font-size:16px;">';

        $vcm_vac_disp = '—';
        if (!empty($req->vcm_vacuum_req)) {
            $vac = json_decode($req->vcm_vacuum_req, true);
            if (is_array($vac)) {
                $vcm_vac_disp = esc_html($vac['mantissa']??'').' &times; 10<sup>'.esc_html($vac['exponent']??'').'</sup> mBar';
            } else {
                $vcm_vac_disp = esc_html($req->vcm_vacuum_req);
            }
        }

        $vcm_fields = [
            'Vacuum Requirement (mbar)' => $vcm_vac_disp,
            'Duration (H)'             => esc_html($req->vcm_duration ?? '').' H',
            'No. of Samples Loaded'    => esc_html($req->vcm_samples_loaded ?? ''),
            'Hot Bar Temperature (&deg;C)' => esc_html($req->vcm_temp_hot_bar ?? '').' &deg;C (&plusmn;'.esc_html($req->vcm_temp_hot_bar_tol ?? '').')',
            'Cold Bar Temperature (&deg;C)' => esc_html($req->vcm_temp_cold_bar ?? '').' &deg;C (&plusmn;'.esc_html($req->vcm_temp_cold_bar_tol ?? '').')',
        ];
        foreach ($vcm_fields as $label => $val) {
            echo '<tr><th style="'.$th_style.'">'.esc_html($label).'</th>';
            echo '<td style="'.$td_style.'">'.($val ?: '—').'</td></tr>';
        }
        echo '</table></div>'.$box_close;
    }

    // Leak Test (MSLD)
    if (in_array('Leak Test of Vac. Elements using MSLD', $types)) {
        echo $box_open;
        echo '<div style="'.$hdr_style.'">&#9654; Leak Test of Vac. Elements using MSLD — Test Details</div>';
        echo '<div style="padding:12px;">';
        echo '<table style="width:100%;border-collapse:collapse;font-size:16px;margin-bottom:15px;background:#fff;">';
        echo '<thead><tr><th style="'.$th_style.'">Sl.</th><th style="'.$th_style.'">Description</th><th style="'.$th_style.'">Qty</th><th style="'.$th_style.'">Remarks</th></tr></thead>';
        echo '<tbody>';
        $msld_rows = [
            "Heat pipe / HMC cases",
            "Thermal / Electrical / RF / Thermocouple feedthrough",
            "Shrouds / Bellows",
            "Vacuum lines & fittings",
            "Wave guides / Valves / Gauges",
            "Others"
        ];
        $msld_data = json_decode($req->msld_samples_json ?? '[]', true);
        foreach($msld_rows as $idx => $desc) {
            $row = $msld_data[$idx] ?? ['qty'=>'','remarks'=>'','others_spec'=>''];
            echo '<tr>';
            echo '<td style="'.$td_style.';text-align:center;">'.($idx+1).'</td>';
            echo '<td style="'.$td_style.'">';
            echo esc_html($desc);
            if ($desc === "Others" && !empty($row['others_spec'])) {
                echo ' ('.esc_html($row['others_spec']).')';
            }
            echo '</td>';
            echo '<td style="'.$td_style.'">'.esc_html($row['qty'] ?? '').'</td>';
            echo '<td style="'.$td_style.'">'.esc_html($row['remarks'] ?? '').'</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>'.$box_close;
    }

    // Calibration of Vacuum Gauges
    if (in_array('Calibration of Vacuum Gauges', $types)) {
        $gauge_data = json_decode($req->gauge_calibration_json ?? '{}', true);
        echo $box_open;
        echo '<div style="'.$hdr_style.'">&#9654; I. CALIBRATION OF VACUUM GAUGES</div>';
        echo '<div style="padding:12px;">';
        echo '<table style="width:100%;border-collapse:collapse;font-size:16px;margin-bottom:15px;background:#fff;">';
        echo '<thead><tr><th style="'.$th_style.';width:80px;">Gauge No.</th><th style="'.$th_style.'">Make</th><th style="'.$th_style.'">Model</th><th style="'.$th_style.'">Sl. No.</th><th style="'.$th_style.'">Range</th></tr></thead>';
        echo '<tbody>';
        $gauges = $gauge_data['gauges'] ?? [];
        for($i=0; $i<4; $i++) {
            $g = $gauges[$i] ?? ['make'=>'','model'=>'','slno'=>'','range'=>''];
            echo '<tr>';
            echo '<td style="'.$td_style.';text-align:center;">'.($i+1).'</td>';
            echo '<td style="'.$td_style.'">'.esc_html($g['make']).'</td>';
            echo '<td style="'.$td_style.'">'.esc_html($g['model']).'</td>';
            echo '<td style="'.$td_style.'">'.esc_html($g['slno']).'</td>';
            echo '<td style="'.$td_style.'">'.esc_html($g['range']).'</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        
        echo '<p style="font-weight:700;margin-top:20px;">Type of reference Gauge needed:</p>';
        echo '<table style="width:100%;border-collapse:collapse;font-size:16px;background:#fff;margin-bottom:15px;">';
        echo '<thead><tr><th style="'.$th_style.';width:50px;">Select</th><th style="'.$th_style.'">Name of the Gauge head</th><th style="'.$th_style.'">Working Pressure Range</th><th style="'.$th_style.'">Remarks</th></tr></thead>';
        echo '<tbody>';
        $ref_gauges = [
            "Spinning Rotor Gauge" => "1x 10⁻² mbar to 1x 10⁻⁷ mbar.",
            "Capacitance Manometer" => "1000 torr / 1 torr / 0.05 torr",
            "BA - Ion Gauge" => "1e-4mbar to 1e-10mbar"
        ];
        $selected_refs = (array)($gauge_data['refs'] ?? []);
        $refs_full = (array)($gauge_data['refs_full'] ?? []);
        foreach($ref_gauges as $name => $range) {
            $is_sel = in_array($name, $selected_refs) || isset($refs_full[$name]);
            $rem = $refs_full[$name] ?? '';
            echo '<tr>';
            echo '<td style="'.$td_style.';text-align:center;">'.($is_sel ? '&#9745;' : '&#9744;').'</td>';
            echo '<td style="'.$td_style.';font-weight:600;">'.esc_html($name).'</td>';
            echo '<td style="'.$td_style.'">'.esc_html($range).'</td>';
            echo '<td style="'.$td_style.'">'.esc_html($rem).'</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        
        echo '<br><p style="text-decoration:underline; font-weight:700; font-size:16px; margin-top:20px;">II. CORONA / HIGH ALTITUDE TEST</p>';
        echo '<table style="width:100%;border-collapse:collapse;font-size:16px;background:#fff;margin-bottom:20px;">';
        echo '<thead><tr><th style="'.$th_style.';width:50px;">Sl.</th><th style="'.$th_style.'">Details of feedthroughs</th><th style="'.$th_style.';width:80px;">Qty</th><th style="'.$th_style.'">Vacuum Requirement</th><th style="'.$th_style.'">Duration</th><th style="'.$th_style.'">Remarks</th></tr></thead>';
        echo '<tbody>';
        $corona_data = json_decode($req->corona_test_json ?? '[]', true);
        for($i=0; $i<3; $i++) {
            $c = $corona_data[$i] ?? ['desc'=>'','qty'=>'','vacuum'=>'','duration'=>'','remarks'=>''];
            echo '<tr>';
            echo '<td style="'.$td_style.';text-align:center;">'.($i+1).'</td>';
            echo '<td style="'.$td_style.'">'.esc_html($c['desc']).'</td>';
            echo '<td style="'.$td_style.'">'.esc_html($c['qty']).'</td>';
            echo '<td style="'.$td_style.'">'.esc_html($c['vacuum']).'</td>';
            echo '<td style="'.$td_style.'">'.esc_html($c['duration']).'</td>';
            echo '<td style="'.$td_style.'">'.esc_html($c['remarks']).'</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<br><p style="text-decoration:underline; font-weight:700; font-size:16px; margin-top:10px;">III. OTHER SPECIAL TEST (Describe):</p>';
        echo '<div style="border:1px solid #000; padding:10px; background:#fff; white-space:pre-wrap; font-size:15px; min-height:60px;">'.esc_html($req->other_special_test_desc ?? '').'</div>';

        echo '</div>'.$box_close;
    }


    // Bombing & Fine Leak Detection
    if (in_array('Bombing & Fine Leak Detection of Electronic Components', $types)) {
        echo $box_open;
        echo '<div style="'.$hdr_style.'">&#9654; Bombing & Fine Leak Detection — Test Details</div>';
        echo '<div style="padding:12px;">';
        echo '<table style="width:100%;border-collapse:collapse;font-size:16px;background:#fff;">';
        echo '<thead><tr><th style="'.$th_style.';width:50px;">Sl.</th><th style="'.$th_style.'">Name of Components</th><th style="'.$th_style.';width:80px;">Qty</th><th style="'.$th_style.'">Pressure (PSI)</th><th style="'.$th_style.'">Dwell Time</th><th style="'.$th_style.'">Permissible Rate</th></tr></thead>';
        echo '<tbody>';
        $bombing_data = json_decode($req->bombing_leak_test_json ?? '[]', true);
        for($i=0; $i<5; $i++) {
            $b = $bombing_data[$i] ?? ['name'=>'','qty'=>'','pressure'=>'','dwell'=>'','rate'=>''];
            echo '<tr>';
            echo '<td style="'.$td_style.';text-align:center;">'.($i+1).'</td>';
            echo '<td style="'.$td_style.'">'.esc_html($b['name']).'</td>';
            echo '<td style="'.$td_style.'">'.esc_html($b['qty']).'</td>';
            echo '<td style="'.$td_style.'">'.esc_html($b['pressure']).'</td>';
            echo '<td style="'.$td_style.'">'.esc_html($b['dwell']).'</td>';
            echo '<td style="'.$td_style.'">'.esc_html($b['rate']).'</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>'.$box_close;
    }
}

// =====================================================================
//  PIPELINE RENDERER
// =====================================================================

function uhv_pipeline($steps) {
    echo '<div class="uhv-pipeline" style="display:flex;align-items:flex-start;justify-content:space-between;margin:30px 0;position:relative;overflow-x:auto;padding-bottom:15px;">';
    $last_done = -1;
    $total = count($steps);
    foreach ($steps as $i => $s) { if (!empty($s['done'])) $last_done = $i; }
    
    $rejected_index = -1;
    foreach ($steps as $i => $s) { if (!empty($s['rejected'])) { $rejected_index = $i; break; } }
    
    foreach ($steps as $i => $s) {
        $done   = !empty($s['done']);
        $is_rejected = !empty($s['rejected']);
        
        if ($is_rejected) {
            $active = false;
        } else {
            $active = !$done && ($i === $last_done + 1) && ($rejected_index === -1);
        }
        
        // Element: Circle + colors
        if ($is_rejected) {
            $bg = '#dc3545';
            $co = '#fff';
            $border = '#dc3545';
            $glow = 'box-shadow: 0 0 10px rgba(220,53,69,0.5), 0 0 0 4px #fff;';
            $icon = '✗';
            $label_col = '#dc3545';
            $sub = 'Rejected';
            if (!empty($s['date'])) { $ts=strtotime($s['date']); $sub=$ts?date('d M, h:i A',$ts):$s['date']; }
            $sub_col = '#dc3545';
            $sub_bg = 'rgba(220,53,69,0.1)';
        } elseif ($done) {
            $bg = '#28a745';
            $co = '#fff';
            $border = '#28a745';
            $glow = 'box-shadow: 0 0 10px rgba(40,167,69,0.5), 0 0 0 4px #fff;';
            $icon = '✓';
            $label_col = '#28a745';
            $sub = 'Done';
            if (!empty($s['date'])) { $ts=strtotime($s['date']); $sub=$ts?date('d M, h:i A',$ts):$s['date']; }
            $sub_col = '#28a745'; 
            $sub_bg = 'rgba(40,167,69,0.1)';
        } elseif ($active) {
            $bg = '#ffc107';
            $co = '#000';
            $border = '#ffc107';
            $glow = 'box-shadow: 0 0 12px rgba(255,193,7,0.6), 0 0 0 4px #fff;';
            $icon = '●';
            $label_col = '#b8860b';
            $sub = 'In Progress';
            $sub_col = '#b8860b';
            $sub_bg = 'rgba(255,193,7,0.15)';
        } else {
            $bg = '#fff';
            $co = '#888';
            $border = '#adb5bd';
            $glow = 'box-shadow: 0 0 0 4px #fff;';
            $icon = ($i + 1);
            $label_col = '#888';
            $sub = 'Waiting';
            $sub_col = '#aaa';
            $sub_bg = 'transparent';
        }

        // Connector lines
        if ($i < $total - 1) {
            $line_col = ($i < $last_done && $rejected_index === -1) ? '#28a745' : '#ddd';
            echo "<div style='position:absolute;top:20px;left:".( ( ($i+0.5)/$total ) * 100 )."%;width:".(100/$total)."%;height:2px;background:$line_col;z-index:0;'></div>";
        }

        echo "<div style='flex:1;text-align:center;min-width:120px;position:relative;z-index:1;'>";
        echo "<div style='width:40px;height:40px;border-radius:50%;background:$bg;color:$co;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:16px;font-weight:700;border:3px solid $border; $glow transition:all .3s;'>$icon</div>";
        echo "<div style='font-size:12px;font-weight:700;text-transform:uppercase;color:$label_col;letter-spacing:.3px;padding:0 5px;'>".esc_html($s['label'])."</div>";
        echo "<div style='font-size:10px;color:$sub_col;margin-top:6px;background:$sub_bg;display:inline-block;padding:2px 8px;border-radius:10px;font-weight:600;'>".esc_html($sub)."</div>";
        echo "</div>";
    }
    echo '</div>';
}

// =====================================================================
//  SHARED: QA HISTORY DETAIL (read-only) — used by indenter, qa_engineer, manager
// =====================================================================
function uhv_qa_history_detail($req, $back_url, $role_label, $show_wrapper = true) {
    if ($show_wrapper): ?>
<div class="container">
<div class="role-indicator"><?php echo esc_html($role_label); ?></div>
<?php endif; ?>
<div style="margin-bottom:20px;display:flex;gap:10px;flex-wrap:wrap;">
  <a href="<?php echo esc_url($back_url); ?>" class="btn btn-primary">&larr; Back</a>
</div>
<div class="request-card">
  <div class="request-header">
    <div>
      <h2 style="margin:0;font-size:22px;">QA Review Details &mdash; <?php echo esc_html($req->test_requisition_no); ?></h2>
      <small style="color:#666;"><?php echo esc_html($req->satellite_name.' | '.$req->project_program); ?></small><br>
      <small style="color:#666;">Submitted by <strong><?php echo esc_html($req->sub_name); ?></strong> on <?php echo date('d M Y, h:i A', strtotime($req->submission_date)); ?></small>
    </div>
    <?php
      $hbc = 'badge-pending';
      if ($req->status==='approved')   $hbc='badge-approved';
      if ($req->status==='rejected')   $hbc='badge-rejected';
      if ($req->status==='completed')  $hbc='badge-completed';
      if ($req->status==='qa_rejected')$hbc='badge-qa-rejected';
    ?>
    <span class="badge <?php echo $hbc; ?>"><?php echo strtoupper(str_replace('_',' ',$req->status)); ?></span>
  </div>

  <h3>Submitter Details</h3>
  <table>
    <tr><th style="width:20%">Name</th><td style="width:30%"><?php echo esc_html($req->sub_name); ?></td><th style="width:20%">Staff No.</th><td><?php echo esc_html($req->sub_stno); ?></td></tr>
    <tr><th>Designation</th><td><?php echo esc_html($req->sub_designation?:'—'); ?></td><th>Phone</th><td><?php echo esc_html($req->sub_phone?:'—'); ?></td></tr>
    <tr><th>Section / Division</th><td colspan="3"><?php echo esc_html(($req->sub_section?:'—').' / '.($req->sub_division?:'—')); ?></td></tr>
  </table>

  <h3>Test Object Details</h3>
  <table>
    <tr><th style="width:25%">Test Object</th><td colspan="3"><?php echo esc_html($req->satellite_name); ?></td></tr>
    <tr><th>Test Type</th><td><?php echo esc_html(!empty($req->test_types) ? $req->test_types : $req->test_type); ?></td><th>Test Required on</th><td><?php echo !empty($req->test_required_on)?date('d M Y',strtotime($req->test_required_on)):'—'; ?></td></tr>
    <?php if (!empty($req->special_requirements)): ?>
    <tr><th>Special Requirements</th><td colspan="3" style="background:#fff3cd;"><?php echo nl2br(esc_html($req->special_requirements)); ?></td></tr>
    <?php endif; ?>
  </table>
  <?php uhv_render_test_subforms_readonly($req); ?>
  <?php uhv_render_per_test_risk_readonly($req, 'Staff Risk Assessment (Per Test)'); ?>
  <?php uhv_render_execution_details_readonly($req); ?>
  <div style="margin-top:25px;padding:22px;border:2px solid #6f42c1;background:#faf7ff;border-radius:6px;">
    <h4 style="margin:0 0 14px;font-size:18px;font-weight:700;color:#6f42c1;text-transform:uppercase;letter-spacing:.5px;">&#9876; QA / T&amp;E Review (Read-Only)</h4>
    <table style="width:100%;border-collapse:collapse;font-size:17px;">
      <tr><td style="border:1px solid #ddd;padding:12px;background:#f5f5f5;font-weight:700;width:30%;">Reviewed By</td><td style="border:1px solid #ddd;padding:12px;"><?php echo esc_html($req->qa_reviewer_name?:'—'); ?></td></tr>
      <tr><td style="border:1px solid #ddd;padding:12px;background:#f5f5f5;font-weight:700;">Review Date</td><td style="border:1px solid #ddd;padding:12px;"><?php echo !empty($req->qa_review_date)?date('d M Y, h:i A',strtotime($req->qa_review_date)):'—'; ?></td></tr>
      <tr><td style="border:1px solid #ddd;padding:12px;background:#f5f5f5;font-weight:700;">Decision</td><td style="border:1px solid #ddd;padding:12px;"><?php echo $req->qa_decision==='accept'?'<span style="color:#28a745;font-weight:700;">&#10003; Accepted &amp; Forwarded to Manager</span>':'<span style="color:#fd7e14;font-weight:700;">&#10007; Rejected &amp; Returned to User</span>'; ?></td></tr>
      <?php if (!empty($req->qa_remarks)): ?>
      <tr><td style="border:1px solid #ddd;padding:12px;background:#f5f5f5;font-weight:700;vertical-align:top;">Remarks</td><td style="border:1px solid #ddd;padding:12px;"><?php echo nl2br(esc_html($req->qa_remarks)); ?></td></tr>
      <?php endif; ?>
    </table>
  </div>
</div>
<?php if ($show_wrapper): ?>
</div>
<?php endif;
}

// =====================================================================
//  USER VIEW (subsystem engineers + any other employee submitting a TR)
//  UHV staff may enter here only for QA dashboard when nominated (same routes as other users).
// =====================================================================
$uhv_in_user_qa_flow = ($user_role === 'UHV' && ($_GET['action'] ?? '') === 'qa_dashboard');
if (in_array($user_role, ['indenter', 'tr_submitter'], true) || $uhv_in_user_qa_flow) {
    $action  = $_GET['action'] ?? 'dashboard';
    $view_id = intval($_GET['view_id'] ?? 0);

    // ---------------------------------------------------------------
    // ACTION: View Staff Form dashboard (any employee)
    // ---------------------------------------------------------------
    if ($action === 'view_staff') {
        $complete_id = intval($_GET['complete_id'] ?? 0);

        if ($complete_id) {
            // ── Individual staff form ──
            $fd = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id=%d AND status IN ('pending_staff','recheck_staff','approved','completed')", $complete_id
            ));
            if ($fd): ?>
<div class="form-container">
<div class="role-indicator">UHV STAFF FORM | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>
<div style="margin-bottom:20px;display:flex;gap:10px;flex-wrap:wrap;">
  <a href="<?php echo esc_url(add_query_arg('action','view_staff', remove_query_arg('complete_id', get_permalink()))); ?>" class="btn btn-primary">&larr; Back to Staff Form List</a>
  <a href="<?php echo get_permalink(); ?>" class="btn btn-primary" style="background:#555;">&larr; My Dashboard</a>
</div>
<?php if (!empty($fd->draft_saved_at)): ?>
<div class="draft-notice"><strong>&#128203; Draft Saved:</strong> Last saved by <strong><?php echo esc_html($fd->draft_saved_by ?? 'Unknown'); ?></strong> on <strong><?php echo date('d M Y, h:i A', strtotime($fd->draft_saved_at)); ?></strong></div>
<?php endif; ?>
<?php
$uhv_msg = sanitize_text_field($_GET['uhv_msg'] ?? '');
$_vs_errs = get_transient('uhv_errors_'.$user->ID);
if ($uhv_msg === 'validation_error' || !empty($_vs_errs)): 
    if (!empty($_vs_errs)) delete_transient('uhv_errors_'.$user->ID);
?>
<div style="background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;padding:14px 20px;margin-bottom:25px;border-radius:4px;">
    <strong>&#9888; Please fix the following errors:</strong>
    <ul style="margin:10px 0 0 20px; padding:0;">
        <?php 
        if (!empty($_vs_errs)) {
            foreach ($_vs_errs as $err) echo '<li>'.esc_html($err).'</li>';
        } else {
            echo '<li>All fields in the Staff Review section are mandatory before forwarding to the manager.</li>';
        }
        ?>
    </ul>
</div>
<?php endif; ?>
<h1>UHV Staff Form &mdash; <?php echo esc_html($fd->test_requisition_no); ?></h1>
<?php
// Pipeline for this form
$_vs_steps = uhv_get_extended_pipeline_steps($fd);
echo '<div style="background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:18px 22px;margin:0 0 22px 0;">';
echo '<h3 style="margin-top:0;font-size:15px;color:#343a40;">Live Progress Pipeline</h3>';
uhv_pipeline($_vs_steps);
echo '</div>';
?>
<?php if ($fd->status === 'completed'): ?>
<div style="background:#d4edda;border-left:4px solid #28a745;padding:12px 18px;margin-bottom:20px;border-radius:4px;font-size:14px;">&#10003; <strong>This form has been completed and is now read-only.</strong></div>
<?php else: ?>
<div style="background:#e3f2fd;border-left:4px solid #2196F3;padding:12px 18px;margin-bottom:20px;border-radius:4px;font-size:14px;">&#128196; Fill in the UHV details for <strong><?php echo esc_html($fd->test_requisition_no); ?></strong> and submit when ready.</div>
<?php endif; ?>

<!-- Reference context panels -->
<?php
$_ref = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $fd->id));
if ($_ref):
?>
<div style="margin-bottom:22px;padding:18px;background:#e3f2fd;border-left:4px solid #2196F3;border-radius:4px;">
  <h3 style="margin-top:0;color:#1565c0;">&#128203; User Request Details (Reference)</h3>
  <table style="width:100%;border-collapse:collapse;font-size:13px;">
    <tr><th style="border:1px solid #ccc;padding:9px;background:#f5f5f5;width:30%;">Test Object</th><td style="border:1px solid #ccc;padding:9px;"><?php echo esc_html($_ref->satellite_name); ?></td></tr>
    <tr><th style="border:1px solid #ccc;padding:9px;background:#f5f5f5;">Test Type</th><td style="border:1px solid #ccc;padding:9px;"><?php echo esc_html(!empty($_ref->test_types) ? $_ref->test_types : $_ref->test_type); ?></td></tr>
    <tr><th style="border:1px solid #ccc;padding:9px;background:#f5f5f5;">Subsystem Engineer</th><td style="border:1px solid #ccc;padding:9px;"><?php echo esc_html($_ref->sub_name.' ('.$_ref->sub_stno.')'); ?></td></tr>
    <?php if (!empty($_ref->special_requirements)): ?>
    <tr><th style="border:1px solid #ccc;padding:9px;background:#f5f5f5;vertical-align:top;">Special Req.</th><td style="border:1px solid #ccc;padding:9px;"><?php echo nl2br(esc_html($_ref->special_requirements)); ?></td></tr>
    <?php endif; ?>
  </table>
  <?php uhv_render_test_subforms_readonly($_ref); ?>
</div>
<?php if (!empty($_ref->qa_reviewer_name)): ?>
<div style="margin-bottom:22px;padding:18px;background:#f3e5f5;border-left:4px solid #9c27b0;border-radius:4px;">
  <h3 style="margin-top:0;color:#6a1b9a;">&#10003; QA / T&amp;E Engineer Review</h3>
  <table style="width:100%;border-collapse:collapse;font-size:13px;">
    <tr><th style="border:1px solid #ccc;padding:9px;background:#f5f5f5;width:30%;">Reviewed By</th><td style="border:1px solid #ccc;padding:9px;"><?php echo esc_html($_ref->qa_reviewer_name); ?></td></tr>
    <tr><th style="border:1px solid #ccc;padding:9px;background:#f5f5f5;">Decision</th><td style="border:1px solid #ccc;padding:9px;"><strong style="color:<?php echo $_ref->qa_decision==='accept'?'#28a745':'#fd7e14'; ?>;"><?php echo $_ref->qa_decision==='accept'?'&#10003; Accepted':'&#10007; Rejected'; ?></strong></td></tr>
    <?php if (!empty($_ref->qa_remarks)): ?><tr><th style="border:1px solid #ccc;padding:9px;background:#f5f5f5;vertical-align:top;">Remarks</th><td style="border:1px solid #ccc;padding:9px;"><?php echo nl2br(esc_html($_ref->qa_remarks)); ?></td></tr><?php endif; ?>
  </table>
</div>
<?php endif; ?>
<?php if (!empty($_ref->reviewed_by)): ?>
<div style="margin-bottom:22px;padding:18px;background:#fff3e0;border-left:4px solid #ff9800;border-radius:4px;">
  <h3 style="margin-top:0;color:#e65100;">&#10003; Manager Approval</h3>
  <table style="width:100%;border-collapse:collapse;font-size:13px;">
    <tr><th style="border:1px solid #ccc;padding:9px;background:#f5f5f5;width:30%;">Reviewed By</th><td style="border:1px solid #ccc;padding:9px;"><?php echo esc_html($_ref->reviewed_by); ?></td></tr>
    <tr><th style="border:1px solid #ccc;padding:9px;background:#f5f5f5;">Approved On</th><td style="border:1px solid #ccc;padding:9px;"><?php echo !empty($_ref->approval_date)?date('d M Y, h:i A',strtotime($_ref->approval_date)):'&mdash;'; ?></td></tr>
    <?php if (!empty($_ref->manager_comment)): ?><tr><th style="border:1px solid #ccc;padding:9px;background:#f5f5f5;vertical-align:top;">Comment</th><td style="border:1px solid #ccc;padding:9px;"><?php echo nl2br(esc_html($_ref->manager_comment)); ?></td></tr><?php endif; ?>
  </table>

  <!-- Section A Ref -->
  <h4 style="margin-top:15px;color:#e65100;font-size:14px;">Section A — Pre-Test Assessment</h4>
  <table style="width:100%;border-collapse:collapse;font-size:13px;background:#fff;">
    <tr><th style="border:1px solid #ccc;padding:6px;background:#f5f5f5;">Risk Assessed?</th><td style="border:1px solid #ccc;padding:6px;"><?php echo esc_html($_ref->risk_assessed_uhv ?: '—'); ?></td><th style="border:1px solid #ccc;padding:6px;background:#f5f5f5;">RPN</th><td style="border:1px solid #ccc;padding:6px;"><?php $rpn_v=$_ref->rpn_uhv??''; echo $rpn_v==='lt4' ? '&lt; 4' : ($rpn_v==='gte5' ? '&ge; 5' : (esc_html($rpn_v)?:'&mdash;')); ?></td></tr>
    <tr><th style="border:1px solid #ccc;padding:6px;background:#f5f5f5;">Test Reviewed?</th><td style="border:1px solid #ccc;padding:6px;"><?php echo esc_html($_ref->test_received_reviewed ?: '—'); ?></td><th style="border:1px solid #ccc;padding:6px;background:#f5f5f5;">Object Accepted?</th><td style="border:1px solid #ccc;padding:6px;"><?php echo esc_html($_ref->test_object_accepted ?: '—'); ?></td></tr>
    <tr><th style="border:1px solid #ccc;padding:6px;background:#f5f5f5;">Accepted By</th><td style="border:1px solid #ccc;padding:6px;" colspan="3"><?php echo esc_html($_ref->test_accepted_by ?: '—'); ?></td></tr>
  </table>
  <?php uhv_render_execution_details_readonly($_ref); ?>
</div>
<?php endif; ?>
<?php endif; // $_ref ?>

<?php if (in_array($fd->status, ['pending_staff', 'recheck_staff'])): ?>
<!-- ══ PHASE 1: STAFF REVIEW (Sync with UHV Block & Image 1) ══ -->
<div style="margin:25px 0; padding:30px; border:2px solid #0d6efd; background:#fff; border-radius:10px; box-shadow: 0 4px 15px rgba(13, 110, 253, 0.08);">
  <h2 style="text-align:center; font-size:22px; font-weight:700; margin:0 0 10px 0; text-transform:uppercase;">(TO BE FILLED BY UHV STAFF)</h2>
  <p style="text-align:center; color:#666; font-size:14px; margin-bottom:25px;">Assess each test requisitioned per QMS guidelines.</p>
  
  <form method="post" enctype="multipart/form-data">
    <?php wp_nonce_field('uhv_action','uhv_nonce'); ?>
    <input type="hidden" name="form_id" value="<?php echo intval($fd->id); ?>">
    <input type="hidden" name="uhv_staff_form_marker" value="1">
    
    <?php 
    $risk_map = uhv_get_per_test_risk($fd);
    $labels_ordered = uhv_get_selected_test_labels($fd);
    foreach ($labels_ordered as $i => $test_label):
        $risk = $risk_map[$test_label] ?? ['test_object_accepted'=>'','risk_assessed_uhv'=>'','rpn_uhv'=>'','risk_record_uhv'=>'','risk_table_url'=>''];
    ?>
    <div style="margin-bottom:35px; border-bottom:2px solid #eee; padding-bottom:25px;">
      <h3 style="margin:0 0 15px; font-size:18px; color:#0d6efd; font-weight:700;">Test: <?php echo esc_html($test_label); ?></h3>
      <table style="width:100%; border-collapse:collapse; background:#fff; font-size:16px; border:1px solid #000;">
        <tr>
          <td style="border:1px solid #000; padding:15px; width:65%; font-weight:500;">1. Test request received, reviewed and accepted for testing</td>
              <td style="border:1px solid #000; padding:15px; width:35%; text-align:center;">
                <div style="display:flex; justify-content:center; gap:15px;">
                  <label><input type="radio" name="risk_test_object_accepted[<?php echo $i; ?>]" value="yes" <?php checked(strtolower($risk['test_object_accepted'] ?? ''),'yes'); ?>> Yes</label>
                  <label><input type="radio" name="risk_test_object_accepted[<?php echo $i; ?>]" value="no" <?php checked(strtolower($risk['test_object_accepted'] ?? ''),'no'); ?>> No</label>
                </div>
              </td>
            </tr>
            <tr>
              <td style="border:1px solid #000; padding:15px; vertical-align:middle;">
                <div style="margin-bottom:8px; font-weight:500;">2. Risk Assessed as per Online QMS UHV Lab Risk Table</div>
                <div style="display:flex; gap:15px;">
                  <label><input type="radio" name="risk_assessed_uhv[<?php echo $i; ?>]" value="yes" <?php checked(strtolower($risk['risk_assessed_uhv'] ?? ''),'yes'); ?>> Yes</label>
                  <label><input type="radio" name="risk_assessed_uhv[<?php echo $i; ?>]" value="no" <?php checked(strtolower($risk['risk_assessed_uhv'] ?? ''),'no'); ?>> No</label>
                </div>
              </td>
              <td style="border:1px solid #000; padding:15px; vertical-align:middle;">
                <div style="margin-bottom:10px; font-weight:500;">3. Risk Priority No.(RPN):</div>
                <div style="display:flex; gap:15px; align-items:center;">
                  <label>&le; 4 <input type="radio" name="rpn_uhv[<?php echo $i; ?>]" value="lt4" <?php checked($risk['rpn_uhv'] ?? '','lt4'); ?> onclick="uhvIndRpnUpload(<?php echo (int) $i; ?>,'lt4')"></label>
                  <label>&ge; 5 <input type="radio" name="rpn_uhv[<?php echo $i; ?>]" value="gte5" <?php checked($risk['rpn_uhv'] ?? '','gte5'); ?> onclick="uhvIndRpnUpload(<?php echo (int) $i; ?>,'gte5')"></label>
                </div>
              </td>
              <td style="border:1px solid #000; padding:15px; text-align:center; vertical-align:middle;">
                 <div style="margin-bottom:8px; font-weight:500;">4. Risk Record:</div>
                 <div style="display:flex; justify-content:center; gap:8px;">
                   <label><input type="radio" name="risk_record_uhv[<?php echo $i; ?>]" value="yes" <?php checked(strtolower($risk['risk_record_uhv'] ?? ''),'yes'); ?>> Yes</label>
                   <label><input type="radio" name="risk_record_uhv[<?php echo $i; ?>]" value="no" <?php checked(strtolower($risk['risk_record_uhv'] ?? ''),'no'); ?>> No</label>
                   <label><input type="radio" name="risk_record_uhv[<?php echo $i; ?>]" value="na" <?php checked(strtolower($risk['risk_record_uhv'] ?? ''),'na'); ?>> NA</label>
                 </div>
              </td>
        </tr>
      </table>
      <div id="risk_upload_ind_<?php echo (int) $i; ?>" style="display:<?php echo (($risk['rpn_uhv'] ?? '') === 'gte5') ? 'block' : 'none'; ?>; margin-top:12px; border:1px dashed #0d6efd; padding:10px; border-radius:4px; background:#f0f7ff;">
        <label style="display:block; font-size:13px; font-weight:600; margin-bottom:6px; color:#0d6efd;">Upload Risk Table (PDF/Image) <span style="color:#dc3545;">*</span> <small style="font-weight:400;">(required if RPN &ge; 5)</small></label>
        <input type="file" name="risk_table_file_<?php echo (int) $i; ?>" accept=".pdf,image/*" style="font-size:13px; width:100%; max-width:420px;">
        <?php if (!empty($risk['risk_table_url'])): ?>
          <div style="margin-top:6px;"><small>Existing: <a href="<?php echo esc_url($risk['risk_table_url']); ?>" target="_blank" rel="noopener">View file</a></small></div>
          <input type="hidden" name="existing_risk_table_url[<?php echo (int) $i; ?>]" value="<?php echo esc_attr($risk['risk_table_url']); ?>">
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <div style="background:#f8f9fa; padding:15px; border:1px solid #ddd; border-radius:8px; margin-bottom:20px;">
      <label style="display:block; margin-bottom:8px; font-weight:700; color:#333;">Review Remarks / Recheck Instructions:</label>
      <textarea name="staff_review_comment" placeholder="Enter comments for the manager or instructions for user recheck..." style="width:100%; min-height:80px; border:1px solid #ccc; border-radius:4px; padding:10px; font-family:inherit; font-size:14px;"></textarea>
    </div>

    <div style="margin-top:40px; border-top:2px solid #eee; padding-top:25px; display:flex; gap:15px; justify-content:flex-end; align-items:center;">
      <button type="submit" name="staff_review_action" value="recheck_indenter" class="btn btn-reject" style="background:#fd7e14; border:none; padding:12px 25px; font-weight:600; color:#fff;" onclick="return confirm('Send back for clarification?');">↩ Send to User for Recheck</button>
      <button type="submit" name="staff_review_action" value="forward_manager" class="btn btn-approve" style="background:#28a745; border:none; padding:12px 35px; font-weight:700; color:#fff; font-size:16px;">Forward to Manager &rarr;</button>
    </div>
  </form>
  <script>
  function uhvIndRpnUpload(idx, val) {
    var d = document.getElementById('risk_upload_ind_' + idx);
    if (d) { d.style.display = (val === 'gte5') ? 'block' : 'none'; }
  }
  </script>
</div>
<?php else: ?>
<!-- UHV Fill Form -->
<form method="post" data-form-status="<?php echo esc_attr($fd->status ?? ''); ?>" data-logged-in-name="<?php echo esc_attr($emp->name ?? ''); ?>">
<?php wp_nonce_field('uhv_action','uhv_nonce'); ?>
<input type="hidden" name="form_id" value="<?php echo $fd->id; ?>">
<table>
<tr><th colspan="4" style="background:#000;color:#fff;">Request Information</th></tr>
<tr><th>Test Object</th><th>Test Type</th><th>Test Required on</th></tr>
<tr><td class="view-only"><?php echo esc_html($fd->satellite_name); ?></td><td class="view-only"><?php echo esc_html(!empty($fd->test_types) ? $fd->test_types : $fd->test_type); ?></td><td class="view-only"><?php echo !empty($fd->test_required_on)?date('d M Y',strtotime($fd->test_required_on)):'&mdash;'; ?></td></tr>
<tr><th colspan="2">Subsystem Engineer</th><td colspan="2" class="view-only"><?php echo esc_html($fd->sub_name.' ('.$fd->sub_stno.')'); ?></td></tr>
</table>
<?php uhv_render_test_subforms_readonly($fd); ?>
<!-- ══ PHASE 2: STAFF EXECUTION (Sync with UHV Block & Image 2) ══ -->
<div style="border:1px solid #000; padding:25px; margin:25px 0; background:#fff; border-radius:4px;">
  <h3 style="margin:0 0 20px; text-decoration:underline;">(To Be Filled by UHV Staff)</h3>

  <!-- Read-only risk assessed pre-filled by staff previously -->
  <?php uhv_render_per_test_risk_readonly($fd, 'Section A — Pre-Test Assessment (Filled by Staff)'); ?>

  <div style="margin-top:25px; border:1px solid #000;">
    <h3 style="margin:0; padding:12px; background:#f8f9fa; border-bottom:1px solid #000; font-size:17px; font-weight:700; text-transform:uppercase;">SECTION B — TEST EXECUTION DETAILS</h3>
    <table style="width:100%; border-collapse:collapse; font-size:16px;">
      <tr>
        <th style="border:1px solid #000; padding:15px; text-align:left; background:#fff; width:45%;">Test Started on <span style="color:#dc3545;">*</span></th>
        <td style="border:1px solid #000; padding:10px;"><input type="datetime-local" class="block" name="test_started_datetime" value="<?php echo esc_attr(str_replace(' ','T',$fd->test_started_datetime ?? '')); ?>" style="border:1px solid #ccc; padding:8px;"></td>
      </tr>
      <tr>
        <th style="border:1px solid #000; padding:15px; text-align:left; background:#fff;">Test Completed on <span style="color:#dc3545;">*</span></th>
        <td style="border:1px solid #000; padding:10px;"><input type="datetime-local" class="block" name="test_completed_datetime" value="<?php echo esc_attr(str_replace(' ','T',$fd->test_completed_datetime ?? '')); ?>" style="border:1px solid #ccc; padding:8px;"></td>
      </tr>
      <tr>
        <th style="border:1px solid #000; padding:15px; text-align:left; background:#fff;">Test Duration <small style="font-weight:400; color:#666;">(auto-calculated)</small></th>
        <td style="border:1px solid #000; padding:10px;"><input type="text" class="block" name="test_duration" value="<?php echo esc_attr($fd->test_duration ?? ''); ?>" placeholder="HH:MM:SS" readonly style="background:#f8f9fa; cursor:not-allowed; border:1px solid #ccc; padding:8px;"></td>
      </tr>
      <tr>
        <th style="border:1px solid #000; padding:15px; text-align:left; background:#fff;">Test Completed On-Time?</th>
        <td style="border:1px solid #000; padding:10px;">
          <input type="hidden" name="test_on_time" id="testontime2_val" value="<?php echo esc_attr(ucfirst(strtolower($fd->test_on_time ?? ''))); ?>">
          <div style="display:flex; gap:20px; align-items:center;">
            <label style="cursor:pointer; display:flex; align-items:center; gap:8px;"><input type="checkbox" id="testontime2_yes" <?php echo (strtolower($fd->test_on_time??'')=='yes')?'checked':''; ?> onchange="uhvToggleCb(this,'testontime2_no','testontime2_val','Yes')"> Yes</label>
            <label style="cursor:pointer; display:flex; align-items:center; gap:8px;"><input type="checkbox" id="testontime2_no" <?php echo (strtolower($fd->test_on_time??'')=='no')?'checked':''; ?> onchange="uhvToggleCb(this,'testontime2_yes','testontime2_val','No')"> No</label>
          </div>
        </td>
      </tr>
      <tr>
        <th style="border:1px solid #000; padding:15px; text-align:left; background:#fff;">Test Code</th>
        <td style="border:1px solid #000; padding:10px;"><input type="text" class="block" name="test_code" value="<?php echo esc_attr($fd->test_code ?? ''); ?>" placeholder="e.g. URSC-TC-001" style="border:1px solid #ccc; padding:8px;"></td>
      </tr>
    </table>
  </div>

  <div style="margin-top:25px; border:1px solid #000;">
    <h3 style="margin:0; padding:12px; background:#f8f9fa; border-bottom:1px solid #000; font-size:17px; font-weight:700; text-transform:uppercase;">SECTION C — SPECIMEN COLLECTION &amp; CLOSURE</h3>
    <table style="width:100%; border-collapse:collapse; font-size:16px;">
      <tr style="background:#000; color:#fff;"><th colspan="2" style="padding:10px; text-align:left; font-weight:600;">Test Specimen Collected By</th></tr>
      <tr>
        <th style="border:1px solid #000; padding:15px; text-align:left; background:#fff; width:45%;">Name <span style="color:#dc3545;">*</span></th>
        <td style="border:1px solid #000; padding:10px;"><input type="text" class="block" name="specimen_collected_by_name" value="<?php echo esc_attr($fd->specimen_collected_by_name ?? ''); ?>" placeholder="Auto-filled with your name" data-auto-fill="logged-in-name" style="border:1px solid #ccc; padding:8px;"></td>
      </tr>
      <tr>
        <th style="border:1px solid #000; padding:15px; text-align:left; background:#fff;">Signature / Initials <span style="color:#dc3545;">*</span></th>
        <td style="border:1px solid #000; padding:10px;"><input type="text" class="block" name="specimen_collected_by_sig" value="<?php echo esc_attr($fd->specimen_collected_by_sig ?? ''); ?>" placeholder="Auto-filled with name &amp; timestamp" data-auto-fill="signature" style="border:1px solid #ccc; padding:8px;"></td>
      </tr>
      <tr style="background:#000; color:#fff;"><th colspan="2" style="padding:10px; text-align:left; font-weight:600;">Verification &amp; Requisition Closed By <small style="font-weight:400;">(Dy. Manager UHV or Competent Authority)</small></th></tr>
      <tr>
        <th style="border:1px solid #000; padding:15px; text-align:left; background:#fff; width:45%;">Name <span style="color:#dc3545;">*</span></th>
        <td style="border:1px solid #000; padding:10px;"><input type="text" class="block" name="verification_closed_by_name" value="<?php echo esc_attr($fd->verification_closed_by_name ?? ''); ?>" placeholder="Auto-filled with your name" data-auto-fill="logged-in-name" style="border:1px solid #ccc; padding:8px;"></td>
      </tr>
      <tr>
        <th style="border:1px solid #000; padding:15px; text-align:left; background:#fff;">Signature / Initials <span style="color:#dc3545;">*</span></th>
        <td style="border:1px solid #000; padding:10px;"><input type="text" class="block" name="verification_closed_by_sig" value="<?php echo esc_attr($fd->verification_closed_by_sig ?? ''); ?>" placeholder="Auto-filled with name &amp; timestamp" data-auto-fill="signature" style="border:1px solid #ccc; padding:8px;"></td>
      </tr>
    </table>
  </div>
</div>
<div style="text-align:right;margin-top:30px;display:flex;justify-content:flex-end;gap:15px;flex-wrap:wrap;">
  <button type="submit" name="save_draft" class="btn btn-draft">&#128190; SAVE DRAFT</button>
  <button type="submit" name="complete_uhv" class="btn-complete-submit">&#10003; COMPLETE &amp; SUBMIT</button>
</div>
</form>
</div>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var fs = document.querySelector('[data-form-status]') ? document.querySelector('[data-form-status]').getAttribute('data-form-status') : '';
    var ln = document.querySelector('[data-logged-in-name]') ? document.querySelector('[data-logged-in-name]').getAttribute('data-logged-in-name') : '';
    if (fs === 'completed') {
        var inputs = document.querySelectorAll('form input, form textarea, form button');
        inputs.forEach(function(el) { el.setAttribute('readonly', true); el.setAttribute('disabled', true); el.style.cssText += 'background:#f5f5f5;cursor:not-allowed;color:#666;opacity:.6;'; });
        var lockDiv = document.createElement('div');
        lockDiv.style.cssText = 'background:#f5f5f5;border:2px solid #999;padding:16px 20px;margin:20px 0;border-radius:4px;color:#333;';
        lockDiv.innerHTML = '<strong>&#128274; Form locked</strong> &mdash; Completed and cannot be modified.';
        var frm = document.querySelector('form'); if (frm) frm.parentNode.insertBefore(lockDiv, frm);
    }
    var ts = document.querySelector('[name="test_started_datetime"]');
    var tc = document.querySelector('[name="test_completed_datetime"]');
    var td = document.querySelector('[name="test_duration"]');
    if (ts && tc && td) {
        function calcDur() {
            if (!ts.value || !tc.value) { td.value = ''; return; }
            var diff = new Date(tc.value) - new Date(ts.value);
            if (diff < 0) { td.value = ''; return; }
            var h = Math.floor(diff/3600000), m = Math.floor(diff%3600000/60000), s = Math.floor(diff%60000/1000);
            td.value = ('0'+h).slice(-2)+':'+('0'+m).slice(-2)+':'+('0'+s).slice(-2);
        }
        [ts, tc].forEach(function(el) { el.addEventListener('change', calcDur); });
    }
    if (ln) {
        var frm = document.querySelector('form');
        if (frm) {
            frm.addEventListener('submit', function() {
                function af(nm) { var el = document.querySelector('[name="'+nm+'"]'); if (el && !el.value.trim()) el.value = ln; }
                function afsig(nm) { var el = document.querySelector('[name="'+nm+'"]'); if (el && !el.value.trim()) { var n=new Date(); el.value = ln+' - '+('0'+n.getDate()).slice(-2)+'/'+(('0'+(n.getMonth()+1)).slice(-2))+'/'+n.getFullYear()+' '+('0'+n.getHours()).slice(-2)+':'+('0'+n.getMinutes()).slice(-2); } }
                af('specimen_collected_by_name'); afsig('specimen_collected_by_sig');
                af('verification_closed_by_name'); afsig('verification_closed_by_sig');
            });
        }
    }
});
</script>
<?php
            else:
                echo "<p style='text-align:center;color:#dc3545;padding:50px;'>Form not found or not yet approved.</p>";
            endif;

        } else {
            // ── Staff Form LIST: all approved + completed records ──────────────
            $all_staff_forms = $wpdb->get_results(
                "SELECT * FROM {$table} WHERE status IN ('pending_staff','recheck_staff','approved','completed') ORDER BY approval_date DESC"
            );
?>
<div class="container">
<div class="role-indicator">UHV STAFF FORM | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>
<div style="margin-bottom:20px;"><a href="<?php echo get_permalink(); ?>" class="btn btn-primary">&larr; Back to My Dashboard</a></div>
<h1>UHV Staff Form &mdash; All Approved Requests</h1>
<p style="color:#555;font-size:14px;margin-bottom:22px;">All manager-approved test requests appear below. Click <strong>Fill / View Staff Form</strong> to open the UHV completion form for any request.</p>
<?php if (empty($all_staff_forms)): ?>
<div style="text-align:center;padding:70px;color:#666;border:2px solid #ddd;background:#f9f9f9;border-radius:8px;">
  <h3 style="margin:0 0 10px;font-size:18px;color:#333;">NO APPROVED REQUESTS YET</h3>
  <p style="margin:0;font-size:15px;">Once a manager approves a test request it will appear here.</p>
</div>
<?php else: ?>
<table class="list-table" id="table-staff-form-list">
  <thead>
    <tr><th>TR No.</th><th>Test Object</th><th>Submitted By</th><th>Approved On</th><th>Staff Form Status</th><th>Action</th></tr>
  </thead>
  <tbody>
  <?php foreach ($all_staff_forms as $_sf):
    if ($_sf->status === 'completed') {
        $sf_badge = '<span class="badge badge-completed" style="font-size:11px;padding:4px 10px;">&#10003; COMPLETED</span>';
        $sf_btn_label = 'View (Completed)'; $sf_btn_style = 'background:#555;';
    } elseif (!empty($_sf->draft_saved_at)) {
        $sf_badge = '<span class="badge badge-pending" style="font-size:11px;padding:4px 10px;">&#128203; Draft Saved</span>';
        $sf_btn_label = 'Continue Draft'; $sf_btn_style = '';
    } elseif (!empty($_sf->requisition_received_date)) {
        $sf_badge = '<span class="badge" style="background:#007bff;color:#fff;font-size:11px;padding:4px 10px;">&#9654; In Progress</span>';
        $sf_btn_label = 'Continue Filling'; $sf_btn_style = '';
    } else {
        $sf_badge = '<span class="badge" style="background:#fd7e14;color:#fff;font-size:11px;padding:4px 10px;">&#9203; Not Started</span>';
        $sf_btn_label = 'Fill Staff Form'; $sf_btn_style = '';
    }
  ?>
  <tr>
    <td><strong><?php echo esc_html($_sf->test_requisition_no); ?></strong></td>
    <td><?php echo esc_html($_sf->satellite_name); ?></td>
    <td><?php echo esc_html($_sf->sub_name); ?></td>
    <td><?php echo !empty($_sf->approval_date) ? date('d M Y', strtotime($_sf->approval_date)) : '&mdash;'; ?></td>
    <td><?php echo $sf_badge; ?></td>
    <td>
      <a href="<?php echo esc_url(add_query_arg(['action'=>'view_staff','complete_id'=>$_sf->id], get_permalink())); ?>"
         class="btn btn-view" style="<?php echo $sf_btn_style; ?>"><?php echo $sf_btn_label; ?></a>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
</div>
<?php
        } // end complete_id else

    // ---------------------------------------------------------------
    // ACTION: QA Review dashboard (any employee nominated as QA)
    // ---------------------------------------------------------------
    } elseif ($action === 'qa_dashboard') {
        $qa_view_id   = intval($_GET['qa_view'] ?? 0);
        $uhv_msg_ind = sanitize_text_field($_GET['uhv_msg'] ?? '');

        // All requests where logged-in employee is the nominated QA
        $my_pending_qa = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE qa_stno=%s AND status='pending_qa' ORDER BY submission_date ASC",
            $emp->stno
        ));
        $my_reviewed_qa = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE qa_stno=%s AND qa_decision!='' AND qa_decision IS NOT NULL ORDER BY qa_review_date DESC",
            $emp->stno
        ));
        $my_cnt_accepted = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE qa_stno=%s AND qa_decision='accept'", $emp->stno
        ));
        $my_cnt_rejected = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE qa_stno=%s AND qa_decision='reject'", $emp->stno
        ));

        if (isset($_GET['qa_history_view']) && intval($_GET['qa_history_view']) > 0) {
            // ── Read-only history detail ───────────────────────────────
            $qa_hist_req = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id=%d AND qa_stno=%s",
                intval($_GET['qa_history_view']), $emp->stno
            ));
            if ($qa_hist_req) {
                $back = add_query_arg('action','qa_dashboard', remove_query_arg(['qa_history_view'], get_permalink()));
                uhv_qa_history_detail($qa_hist_req, $back, 'QA REVIEW HISTORY | '.$emp->name.' ('.$emp->stno.')');
            } else {
                echo "<p style='text-align:center;color:#dc3545;padding:50px;'>Record not found.</p>";
            }
        } elseif ($qa_view_id) {
            // ── Individual QA review form (pending only) ───────────────
            $req = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id=%d AND qa_stno=%s AND status='pending_qa'",
                $qa_view_id, $emp->stno
            ));
            if ($req): ?>
<div class="container">
<div class="role-indicator">QA REVIEW | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>
<div style="margin-bottom:20px;display:flex;gap:10px;flex-wrap:wrap;">
  <a href="<?php echo esc_url(add_query_arg('action','qa_dashboard', remove_query_arg(['qa_view','uhv_msg'], get_permalink()))); ?>" class="btn btn-primary">&larr; QA Dashboard</a>
  <a href="<?php echo esc_url(get_permalink()); ?>" class="btn btn-primary" style="background:#555;">&larr; <?php echo $GLOBALS['user_role'] === 'UHV' ? 'Staff Dashboard' : 'My Dashboard'; ?></a>
</div>
<div class="request-card">
  <div class="request-header">
    <div>
      <h2 style="margin:0;font-size:22px;">QA Review &mdash; <?php echo esc_html($req->test_requisition_no); ?></h2>
      <small style="color:#666;"><?php echo esc_html($req->satellite_name); ?></small><br>
      <small style="color:#666;">Submitted by <strong><?php echo esc_html($req->sub_name); ?></strong> on <?php echo date('d M Y, h:i A', strtotime($req->submission_date)); ?></small>
    </div>
    <span class="badge badge-pending-qa">PENDING QA REVIEW</span>
  </div>
  <div style="background:#faf7ff;border-left:4px solid #6f42c1;padding:12px 17px;margin-bottom:20px;border-radius:4px;font-size:14px;color:#4a148c;">
    &#128100; You have been <strong>nominated as QA/T&amp;E Engineer</strong> for this test request.
  </div>
  <h3>Submitter Details</h3>
  <table>
    <tr><th style="width:20%">Name</th><td style="width:30%"><?php echo esc_html($req->sub_name); ?></td><th style="width:20%">Staff No.</th><td><?php echo esc_html($req->sub_stno); ?></td></tr>
    <tr><th>Designation</th><td><?php echo esc_html($req->sub_designation?:'&mdash;'); ?></td><th>Phone</th><td><?php echo esc_html($req->sub_phone?:'&mdash;'); ?></td></tr>
    <tr><th>Section / Division</th><td colspan="3"><?php echo esc_html(($req->sub_section?:'&mdash;').' / '.($req->sub_division?:'&mdash;')); ?></td></tr>
  </table>
  <h3>Test Object Details</h3>
  <table>
    <tr><th style="width:25%">Test Object</th><td style="width:25%"><?php echo esc_html($req->satellite_name); ?></td><th>Test Type</th><td><?php echo esc_html(!empty($req->test_types) ? $req->test_types : $req->test_type); ?></td></tr>
    <tr><th>Test Required on</th><td colspan="3"><?php echo !empty($req->test_required_on)?date('d M Y',strtotime($req->test_required_on)):'&mdash;'; ?></td></tr>
    <?php if (!empty($req->special_requirements)): ?><tr><th>Special Requirements</th><td colspan="3" style="background:#fff3cd;"><?php echo nl2br(esc_html($req->special_requirements)); ?></td></tr><?php endif; ?>
  </table>
  <?php uhv_render_test_subforms_readonly($req); ?>
  <?php uhv_render_per_test_risk_readonly($req, 'Staff Risk Assessment (Per Test)'); ?>
  <form method="post" style="margin-top:30px;">
    <?php wp_nonce_field('uhv_action','uhv_nonce'); ?>
    <input type="hidden" name="form_id" value="<?php echo intval($req->id); ?>">
    <div style="border:2px solid #6f42c1;padding:28px;background:#faf7ff;border-radius:6px;">
      <h4 style="margin:0 0 20px;font-size:16px;font-weight:600;text-transform:uppercase;letter-spacing:.8px;color:#6f42c1;">QA / T&amp;E Engineer Review</h4>
      <table style="width:100%;border-collapse:collapse;margin-bottom:20px;">
        <tr><td style="border:1px solid #ddd;padding:12px;background:#f5f5f5;font-weight:600;width:25%;">Reviewer Name</td><td style="border:1px solid #ddd;padding:12px;"><input class="block" value="<?php echo esc_attr($emp->name); ?>" readonly style="background:#f5f5f5;max-width:350px;"></td></tr>
        <tr><td style="border:1px solid #ddd;padding:12px;background:#f5f5f5;font-weight:600;">Review Date</td><td style="border:1px solid #ddd;padding:12px;"><input class="block" value="<?php echo date('d M Y, h:i A'); ?>" readonly style="background:#f5f5f5;max-width:250px;"></td></tr>
        <tr><td style="border:1px solid #ddd;padding:14px;background:#f5f5f5;font-weight:600;">Remarks / Observations <span style="color:#dc3545;">*</span></td>
          <td style="border:1px solid #ddd;padding:12px;"><textarea name="qa_remarks" rows="4" placeholder="Enter remarks or reason for rejection..." required style="width:100%;border:1px solid #6f42c1;padding:12px;font-size:14px;font-family:inherit;resize:vertical;border-radius:4px;"></textarea></td></tr>
      </table>
      <div style="display:flex;gap:16px;flex-wrap:wrap;">
        <button type="submit" name="qa_decision" value="accept" class="btn btn-approve" onclick="return confirm('Accept and forward to Manager?')">&#10003; ACCEPT &amp; FORWARD TO MANAGER</button>
        <button type="submit" name="qa_decision" value="reject" class="btn btn-reject" onclick="return confirm('Reject and return to User?')">&#10007; REJECT &amp; RETURN TO USER</button>
      </div>
      <p style="font-size:13px;color:#666;margin-top:14px;"><strong>Accept</strong> &rarr; forwarded to Manager &nbsp;|&nbsp; <strong>Reject</strong> &rarr; returned to User with remarks</p>
    </div>
  </form>
</div>
</div>
<?php
            else:
                echo "<p style='text-align:center;color:#dc3545;padding:50px;'>Request not found, already reviewed, or you are not the nominated QA for this request.</p>";
            endif;

        } else {
            // ── QA dashboard listing ───────────────────────────────────
?>
<div class="container">
<div class="role-indicator">QA REVIEW DASHBOARD | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>
<?php if ($uhv_msg_ind === 'qa_accepted'): ?>
<div style="background:#d4edda;color:#155724;border:1px solid #c3e6cb;padding:14px 20px;margin-bottom:20px;border-radius:4px;">&#10003; <strong>Request accepted and forwarded to Manager.</strong></div>
<?php elseif ($uhv_msg_ind === 'qa_rejected'): ?>
<div style="background:#fff3cd;color:#856404;border:1px solid #ffc107;padding:14px 20px;margin-bottom:20px;border-radius:4px;">&#10003; <strong>Request rejected and returned to User with your remarks.</strong></div>
<?php endif; ?>
<div style="margin-bottom:20px;"><a href="<?php echo esc_url(get_permalink()); ?>" class="btn btn-primary">&larr; <?php echo $GLOBALS['user_role'] === 'UHV' ? 'Back to Staff Dashboard' : 'Back to My Dashboard'; ?></a></div>
<h1>My QA Review Dashboard</h1>
<div style="background:#faf7ff;border:2px solid #6f42c1;padding:14px 20px;margin-bottom:25px;border-radius:6px;font-size:14px;">
  &#128100; You are listed as the <strong>nominated QA/T&amp;E Engineer</strong> on the requests below. Review each one and accept or reject.
</div>
<div class="stat-grid" style="grid-template-columns:repeat(3,1fr);">
  <div class="stat-card" style="border-color:#6f42c1;background:#faf7ff;color:#6f42c1;">
    <div class="stat-num"><?php echo count($my_pending_qa); ?></div><div class="stat-lbl">Awaiting My Review</div>
  </div>
  <div class="stat-card sc-approved">
    <div class="stat-num"><?php echo $my_cnt_accepted; ?></div><div class="stat-lbl">Accepted</div>
  </div>
  <div class="stat-card" style="border-color:#fd7e14;background:#fff8f0;color:#7d3c00;">
    <div class="stat-num"><?php echo $my_cnt_rejected; ?></div><div class="stat-lbl">Rejected</div>
  </div>
</div>
<h3>&#9881; Requests Awaiting Your QA Review (<?php echo count($my_pending_qa); ?>)</h3>
<?php if (empty($my_pending_qa)): ?>
<div style="text-align:center;padding:50px;color:#666;border:2px solid #ddd;background:#f9f9f9;border-radius:8px;margin-bottom:30px;">
  <h3 style="margin:0 0 8px;">No Pending Reviews</h3>
  <p style="margin:0;font-size:14px;">You have no requests pending your QA review at this time.</p>
</div>
<?php else: ?>
<table class="list-table" id="table-qa-dashboard-pending" style="margin-bottom:35px;">
  <thead><tr><th>Test Object</th><th>Submitted By</th><th>Submitted Date</th><th>Action</th></tr></thead>
  <tbody>
  <?php foreach ($my_pending_qa as $_qr): ?>
  <tr>
    <td><strong><?php echo esc_html($_qr->satellite_name); ?></strong></td>
    <td><?php echo esc_html($_qr->sub_name); ?></td>
    <td><?php echo date('d M Y, h:i A', strtotime($_qr->submission_date)); ?></td>
    <td><a href="<?php echo esc_url(add_query_arg(['action'=>'qa_dashboard','qa_view'=>$_qr->id], get_permalink())); ?>" class="btn btn-view" style="background:#6f42c1;">Review Request</a></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<?php if (!empty($my_reviewed_qa)): ?>
<h3>&#128203; My QA Review History</h3>
<table class="list-table" id="table-qa-dashboard-history">
  <thead><tr><th>TR No.</th><th>Test Object</th><th>Submitted By</th><th>Submitted Date</th><th>Decision</th><th>Review Date</th><th>Remarks</th><th>Action</th></tr></thead>
  <tbody>
  <?php foreach ($my_reviewed_qa as $_qrh):
    $_dec = ($_qrh->qa_decision==='accept')
        ? '<span class="badge badge-approved" style="font-size:11px;padding:4px 10px;">&#10003; ACCEPTED</span>'
        : '<span class="badge badge-qa-rejected" style="font-size:11px;padding:4px 10px;">&#10007; REJECTED</span>';
    $_tr_disp = (strpos($_qrh->test_requisition_no,'PENDING-')===0||strpos($_qrh->test_requisition_no,'DRAFT-')===0)
        ? '<em style="color:#999;font-size:12px;">Not yet assigned</em>'
        : '<strong>'.esc_html($_qrh->test_requisition_no).'</strong>';
  ?>
  <tr>
    <td><?php echo $_tr_disp; ?></td>
    <td><?php echo esc_html($_qrh->satellite_name); ?></td>
    <td><?php echo esc_html($_qrh->sub_name); ?></td>
    <td><?php echo !empty($_qrh->submission_date) ? date('d M Y, h:i A', strtotime($_qrh->submission_date)) : '—'; ?></td>
    <td><?php echo $_dec; ?></td>
    <td><?php echo !empty($_qrh->qa_review_date)?date('d M Y, h:i A',strtotime($_qrh->qa_review_date)):'&mdash;'; ?></td>
    <td style="max-width:200px;font-size:13px;color:#555;"><?php echo esc_html($_qrh->qa_remarks?:'&mdash;'); ?></td>
    <td><a href="<?php echo esc_url(add_query_arg(['action'=>'qa_dashboard','qa_history_view'=>$_qrh->id], get_permalink())); ?>" class="btn btn-view" style="background:#6f42c1;">View Details</a></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
</div>
<?php
        } // end qa_view_id else

    // ---------------------------------------------------------------
    // DEFAULT: User main dashboard (unchanged)
    // ---------------------------------------------------------------
    } elseif ($action === 'create_new') {
        user_create_new_label:
        // Resume specific draft if requested, or latest draft
        $resume_id = intval($_GET['resume_draft'] ?? 0);
        if ($resume_id) {
            $existing_draft = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id=%d AND user_id=%d AND status IN ('draft_indenter','qa_rejected','rejected','recheck_indenter')",
                $resume_id, $user->ID
            ));
        } else {
            // No resume_draft param = user clicked "+ NEW TEST REQUEST" → always show a blank form
            $existing_draft = null;
        } ?>
<div class="form-container">
<div class="role-indicator">USER VIEW | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>
<div style="margin-bottom:25px;"><a href="<?php echo get_permalink(); ?>" class="btn btn-primary">&larr; Back to Dashboard</a></div>
<h1>New Request Submission</h1>
<?php
$_uhv_errs = get_transient('uhv_errors_'.$user->ID);
if (!empty($_uhv_errs)) {
    delete_transient('uhv_errors_'.$user->ID);
    echo "<div style='background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;padding:14px 20px;margin-bottom:20px;border-radius:4px;font-size:15px;'>";
    echo "<strong>Please fix the following errors:</strong><ul style='margin:8px 0 0 20px;padding:0;'>";
    foreach ($_uhv_errs as $e) echo "<li>".esc_html($e)."</li>";
    echo "</ul></div>";
}
?>
<?php uhv_request_form($emp, $existing_draft, admin_url('admin-ajax.php')); ?>
</div>
<?php

    } else { // dashboard

    if ($view_id) {
        $req = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d AND user_id=%d", $view_id, $user->ID));
        if ($req):
            // Use new extended pipeline function instead of inline steps array
            $steps = uhv_get_extended_pipeline_steps($req);
            $bc='badge-pending';
            if($req->status==='pending_qa')        $bc='badge-pending-qa';
            if($req->status==='qa_rejected')       $bc='badge-qa-rejected';
            if($req->status==='approved')          $bc='badge-approved';
            if($req->status==='rejected')          $bc='badge-rejected';
            if($req->status==='completed')         $bc='badge-completed';
            if($req->status==='recheck_indenter')  $bc='badge-qa-rejected'; // amber styling
?>
<div class="container">
<div class="role-indicator">USER VIEW | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>
<div style="margin-bottom:25px;display:flex;gap:10px;align-items:center;">
  <a href="<?php echo get_permalink(); ?>" class="btn btn-primary">← Back to My Requests</a>
  <?php uhv_print_button($req->id); ?>
  <?php uhv_history_button($req->id); ?>
</div>
<div class="request-card">
  <div class="request-header">
    <div>
      <h2 style="margin:0;font-size:22px;">TR No: <?php echo esc_html($req->test_requisition_no); ?></h2>
      <small style="color:#666;"><?php echo esc_html($req->satellite_name); ?></small><br>
      <small style="color:#666;">Submitted: <?php echo date('d M Y, h:i A', strtotime($req->submission_date)); ?></small>
    </div>
    <span class="badge <?php echo $bc; ?>"><?php echo strtoupper($req->status); ?></span>
  </div>

  <h3 style="margin-top:0;">Live Progress Pipeline</h3>
  <?php uhv_pipeline($steps); ?>

  <h3>Request Details</h3>
  <table>
    <tr><th style="width:20%">Test Object</th><th style="width:30%"><?php echo esc_html($req->satellite_name); ?></th><th style="width:20%">Test Type</th><td><?php echo esc_html(!empty($req->test_types) ? $req->test_types : $req->test_type); ?></td></tr>
    <tr><th>Test Required on</th><td colspan="3"><?php echo !empty($req->test_required_on) ? date('d M Y', strtotime($req->test_required_on)) : '—'; ?></td></tr>
    <tr><th>Subsystem Eng.</th><td><?php echo esc_html($req->sub_name.' ('.$req->sub_stno.')'); ?></td><th>Section</th><td><?php echo esc_html($req->sub_section); ?></td></tr>
    <tr><th>Designation</th><td><?php echo esc_html($req->sub_designation ?: '—'); ?></td><th>Phone</th><td><?php echo esc_html($req->sub_phone ?: '—'); ?></td></tr>
    <?php if($req->qa_exists === 'yes' && !empty($req->qa_name)): ?>
    <tr>
      <th>QA / T&amp;E Engineer</th>
      <td colspan="3"><?php echo esc_html($req->qa_name . ' (' . $req->qa_stno . ')'); ?><br>
        <?php if(!empty($req->qa_designation)): ?><small style="color:#555;"><?php echo esc_html($req->qa_designation); ?></small><br><?php endif; ?>
        <small style="color:#555;"><?php echo esc_html($req->qa_section); ?></small><br>
        <small style="color:#555;">Tel: <?php echo esc_html($req->qa_phone); ?></small>
      </td>
    </tr>
    <?php endif; ?>
    <?php if(!empty($req->special_requirements)):?>
    <tr><th>Special Req.</th><td colspan="3"><?php echo nl2br(esc_html($req->special_requirements)); ?></td></tr>
    <?php endif;?>
  </table>
  <?php uhv_render_test_subforms_readonly($req); ?>
  <?php uhv_render_per_test_risk_readonly($req, 'Staff Risk Assessment (Per Test)'); ?>


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
  <?php if ($req->status === 'recheck_indenter'): ?>
  <div style="background:#fff8e1;border:2px solid #fd7e14;padding:18px 22px;margin:20px 0;border-radius:4px;">
    <strong style="color:#7a3e00;">&#8617; Manager sent this form for Recheck.</strong><br>
    <span style="font-size:14px;color:#555;">Manager's Remarks: <em><?php echo esc_html($req->manager_comment ?: '—'); ?></em></span><br><br>
    <a href="<?php echo esc_url(add_query_arg(['action'=>'create_new','resume_draft'=>$req->id], remove_query_arg(['view_id'], get_permalink()))); ?>" class="btn btn-draft" style="background:#fd7e14;border-color:#fd7e14;">&#9998; Edit &amp; Resubmit</a>
  </div>
  <?php endif; ?>
  <?php if (!empty($req->reviewed_by) || in_array($req->status, ['approved','rejected','completed','recheck_indenter'])): ?>
  <h3>Manager Review</h3>
  <table>
    <tr>
      <th style="width:20%">Reviewed By</th>
      <td style="width:30%"><?php echo esc_html($req->reviewed_by ?: '—'); ?></td>
      <th style="width:20%">Approved On</th>
      <td><?php echo !empty($req->approval_date) ? date('d M Y, h:i A', strtotime($req->approval_date)) : '—'; ?></td>
    </tr>
    <tr>
      <th>Comment</th>
      <td colspan="3"><?php echo esc_html($req->manager_comment ?: '—'); ?></td>
    </tr>
  </table>

  <?php endif; ?>
</div> <!-- /request-card -->
</div> <!-- /container -->
<?php
        else: echo "<p style='text-align:center;color:#dc3545;padding:50px;'>Request not found.</p>"; endif;

    
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
        $my_drafts    = array_filter((array)$all_my, fn($r) => $r->status === 'draft_indenter');
        $my_requests  = array_filter((array)$all_my, fn($r) => $r->status !== 'draft_indenter');
        $has_approved = !empty($latest_approved_id);

        // Stats counts for User (Aligned with CATVAC logic)
        $ind_cnt_pending   = count(array_filter((array)$my_requests, fn($r) => in_array($r->status, ['pending_qa','pending_staff','pending_manager','pending'])));
        $ind_cnt_testing   = count(array_filter((array)$my_requests, fn($r) => in_array($r->status, ['approved', 'in_testing'])));
        $ind_cnt_rejected  = count(array_filter((array)$my_requests, fn($r) => in_array($r->status, ['rejected', 'qa_rejected', 'manager_returned', 'recheck_indenter'])));
        $ind_cnt_completed = count(array_filter((array)$my_requests, fn($r) => $r->status === 'completed'));
        
        $my_qa_pending_count = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE qa_stno=%s AND (status='pending_qa' OR (status IN ('pending_manager','pending') AND qa_decision=''))",
            $emp->stno
        ));

        $uhv_msg    = sanitize_text_field($_GET['uhv_msg'] ?? ''); ?>
<div class="container">
<div class="role-indicator">USER VIEW | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>

<?php if ($uhv_msg === 'draft_saved'): ?>
<div style="background:#d1ecf1;color:#0c5460;border:1px solid #bee5eb;padding:16px 22px;margin-bottom:25px;border-radius:4px;font-size:15px;">
  &#10003; <strong>Draft saved successfully.</strong> You can continue editing it below.
</div>
<?php elseif ($uhv_msg === 'submitted'): ?>
<div style="background:#d4edda;color:#155724;border:1px solid #c3e6cb;padding:16px 22px;margin-bottom:25px;border-radius:4px;font-size:15px;">
  &#10003; <strong>Request submitted successfully.</strong> A Test Requisition Number will be assigned upon manager approval.
</div>
<?php endif; ?>

<?php uhv_user_stat_cards($ind_cnt_pending, $my_qa_pending_count, $ind_cnt_testing, $ind_cnt_rejected, $ind_cnt_completed); ?>

<h1>My UHV Requests</h1>
<div style="margin-bottom:25px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
  <!-- Left: New Test Request button (unchanged) -->
  <a href="<?php echo esc_url(remove_query_arg('resume_draft', add_query_arg('action','create_new'))); ?>" class="btn btn-success">+ NEW TEST REQUEST</a>

  <!-- Right: View Staff Form + QA Review buttons -->
  <?php uhv_dashboard_buttons($emp); ?>
</div>

<?php if (current_user_can('administrator') || isset($_GET['uhv_debug'])): ?>
<div style="background:#f8f9fa;border:1px solid #ccc;padding:12px 16px;margin-bottom:20px;font-size:12px;font-family:monospace;border-radius:4px;">
  <strong>DEBUG</strong> | user_id=<?php echo $user->ID; ?> | email=<?php echo esc_html($user->user_email); ?> | table=<?php echo esc_html($table); ?><br>
  Total records found: <?php echo count($all_my); ?> | Drafts: <?php echo count($my_drafts); ?> | Submitted: <?php echo count($my_requests); ?><br>
  Last DB error: <?php echo esc_html($wpdb->last_error ?: 'none'); ?><br>
  Last query: <?php echo esc_html($wpdb->last_query); ?>
</div>
<?php endif; ?>

<?php if (!empty($my_drafts)): ?>
<h3 style="margin-bottom:12px;">&#128203; Saved Drafts</h3>
<table class="list-table" id="table-user-drafts" style="margin-bottom:35px;">
  <thead><tr><th>Test Object</th><th>Last Saved</th><th>Action</th></tr></thead>
  <tbody>
  <?php foreach($my_drafts as $dr): ?>
  <tr style="background:#fffdf0;">
    <td><?php echo esc_html($dr->satellite_name ?: '(Untitled)'); ?></td>
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
  <p style="margin:0;font-size:15px;"><?php echo !empty($my_drafts) ? 'You have saved drafts above. Complete and submit them for approval.' : 'Click "+ CREATE NEW REQUEST" to fill your first UHV form.'; ?></p>
</div>
<?php else: ?>

<h3 style="margin-bottom:12px;">Submitted Requests</h3>
<table class="list-table" id="table-user-requests">
  <thead>
    <tr>
      <th>TR No.</th>
      <th>Test Object</th>
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
    if($req->status==='completed')        $bc='badge-completed';
    if($req->status==='recheck_indenter') $bc='badge-qa-rejected'; // amber styling
    // Show TR no only if assigned (approved), otherwise show 'Awaiting Approval'
    $tr_display = (strpos($req->test_requisition_no, 'PENDING-') === 0 || strpos($req->test_requisition_no, 'DRAFT-') === 0)
        ? '<em style="color:#999;font-size:12px;">Awaiting Approval</em>'
        : '<strong>'.esc_html($req->test_requisition_no).'</strong>';
  ?>
  <tr<?php echo $req->status==='recheck_indenter' ? ' style="background:#fff8e1;"' : ''; ?>>
    <td><?php echo $tr_display; ?></td>
    <td><?php echo esc_html($req->satellite_name); ?></td>
    <td><?php echo date('d M Y, h:i A', strtotime($req->submission_date)); ?></td>
    <td><span class="badge <?php echo $bc; ?>"><?php echo ($req->status==='recheck_indenter') ? 'SENT BACK' : strtoupper($req->status); ?></span></td>
    <td style="white-space:nowrap;">
      <a href="<?php echo add_query_arg('view_id',$req->id); ?>" class="btn btn-view" style="margin-right:4px;">View Details</a>
      <?php if(in_array($req->status, ['qa_rejected','rejected'])): ?>
      <a href="<?php echo esc_url(add_query_arg(['action'=>'create_new','resume_draft'=>$req->id], get_permalink())); ?>"
         class="btn btn-draft" style="margin-left:0;margin-right:4px;">&#9998; Resubmit</a>
      <?php endif; ?>
      <?php if($req->status === 'recheck_indenter'): ?>
      <a href="<?php echo esc_url(add_query_arg(['action'=>'create_new','resume_draft'=>$req->id], get_permalink())); ?>"
         class="btn" style="background:#fd7e14;color:#fff;margin-left:0;margin-right:4px;">&#9998; Edit &amp; Resubmit</a>
      <?php endif; ?>
      <?php if(in_array($req->status, ['approved','completed'])): ?>
      <a href="<?php echo esc_url(add_query_arg(['action'=>'view_staff','complete_id'=>$req->id], get_permalink())); ?>"
         class="btn" style="background:#17a2b8;color:#fff;padding:6px 12px;font-size:12px;border-radius:4px;text-decoration:none;font-weight:600;">
        &#128196; Staff Form
      </a>
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
    }
}
if ($user_role === 'qa_engineer') {

    $qa_view_id = intval($_GET['qa_view'] ?? 0);
    $uhv_msg   = sanitize_text_field($_GET['uhv_msg'] ?? '');

    // Counts — filtered to only show requests where this engineer is the nominated QA
    $cnt_qa_pending  = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status='pending_qa' AND qa_stno=%s", $emp->stno));
    $cnt_qa_accepted = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status IN ('pending','approved','completed') AND qa_decision='accept' AND qa_stno=%s", $emp->stno));
    $cnt_qa_rejected = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status='qa_rejected' AND qa_stno=%s", $emp->stno));
    $cnt_qa_all      = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE qa_stno=%s AND status NOT IN ('draft_indenter')", $emp->stno));

    if (isset($_GET['qa_history_view']) && intval($_GET['qa_history_view']) > 0) {
        // ── Read-only history detail ───────────────────────────────────────
        $qa_hist_req = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id=%d AND qa_stno=%s",
            intval($_GET['qa_history_view']), $emp->stno
        ));
        if ($qa_hist_req) {
            $back = remove_query_arg('qa_history_view', get_permalink());
            uhv_qa_history_detail($qa_hist_req, $back, 'QA / T&E ENGINEER VIEW — REVIEW HISTORY | '.$emp->name.' ('.$emp->stno.')');
        } else {
            echo "<p style='text-align:center;color:#dc3545;padding:50px;'>Record not found.</p>";
        }
    } elseif ($qa_view_id) {
        // ── QA REVIEW PAGE (pending_qa only) ─────────────────────────────────
        $req = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id=%d AND status='pending_qa' AND qa_stno=%s",
            $qa_view_id, $emp->stno
        ));
        if ($req): ?>
<div class="container">
<div class="role-indicator">QA / T&amp;E ENGINEER VIEW | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>
<div style="margin-bottom:25px;display:flex;gap:10px;align-items:center;">
  <a href="<?php echo get_permalink(); ?>" class="btn btn-primary">← Back to Dashboard</a>
  <?php uhv_history_button($req->id); ?>
</div>

<div class="request-card">
  <div class="request-header">
    <div>
      <h2 style="margin:0;font-size:22px;">Review Request — <?php echo esc_html($req->test_requisition_no); ?></h2>
      <small style="color:#666;"><?php echo esc_html($req->satellite_name); ?></small><br>
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
      <td colspan="3"><?php echo esc_html($req->qa_name.' ('.$req->qa_stno.')'); ?>
        <?php if(!empty($req->qa_designation)): ?> — <small><?php echo esc_html($req->qa_designation); ?></small><?php endif; ?><br>
        <small style="color:#555;"><?php echo esc_html($req->qa_section); ?></small>
      </td>
    </tr>
    <?php endif; ?>
  </table>

  <h3>Test Object Details</h3>
  <table>
    <tr>
      <th style="width:25%">Test Object</th><td style="width:25%"><?php echo esc_html($req->satellite_name); ?></td>
      <th style="width:25%">Test Type</th><td><?php echo esc_html(!empty($req->test_types) ? $req->test_types : $req->test_type); ?></td>
    </tr>
    <tr>
      <th style="width:25%">Test Required on</th><td colspan="3"><?php echo !empty($req->test_required_on) ? date('d M Y', strtotime($req->test_required_on)) : '—'; ?></td>
    </tr>
    <?php if (!empty($req->special_requirements)): ?>
    <tr><th>Special Requirements</th><td colspan="3" style="background:#fff3cd;"><?php echo nl2br(esc_html($req->special_requirements)); ?></td></tr>
    <?php endif; ?>
  </table>
  <?php uhv_render_test_subforms_readonly($req); ?>
  <?php uhv_render_per_test_risk_readonly($req, 'Staff Risk Assessment (Per Test)'); ?>

  <!-- QA REVIEW FORM -->
  <form method="post" style="margin-top:30px;">
    <?php wp_nonce_field('uhv_action','uhv_nonce'); ?>
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
          onclick="return confirm('Reject and return this request to the User?')">
          ✗ REJECT &amp; RETURN TO USER
        </button>
      </div>
      <p style="font-size:13px;color:#666;margin-top:14px;">
        <strong>Accept</strong> → Request forwarded to Manager for approval &nbsp;|&nbsp;
        <strong>Reject</strong> → Request returned to User with your remarks
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
        $pending_qa = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE status='pending_qa' AND qa_stno=%s ORDER BY submission_date ASC",
            $emp->stno
        ));
        $reviewed = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE qa_stno=%s AND qa_decision!='' AND qa_decision IS NOT NULL ORDER BY qa_review_date DESC LIMIT 20",
            $emp->stno
        ));
?>
<div class="container">
<div class="role-indicator">QA / T&amp;E ENGINEER VIEW | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>

<?php if ($uhv_msg === 'qa_accepted'): ?>
<div style="background:#d4edda;color:#155724;border:1px solid #c3e6cb;padding:14px 20px;margin-bottom:20px;border-radius:4px;">
  ✓ <strong>Request accepted and forwarded to Manager for approval.</strong>
</div>
<?php elseif ($uhv_msg === 'qa_rejected'): ?>
<div style="background:#fff3cd;color:#856404;border:1px solid #ffc107;padding:14px 20px;margin-bottom:20px;border-radius:4px;">
  ✓ <strong>Request rejected and returned to the User with your remarks.</strong>
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
<table class="list-table" id="table-qa-pending" style="margin-bottom:35px;">
  <thead>
    <tr>
      <th>TR No.</th>
      <th>Test Object</th>
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
<table class="list-table" id="table-qa-history">
  <thead>
    <tr>
      <th>TR No.</th>
      <th>Test Object</th>
      <th>Submitted By</th>
      <th>Submitted Date</th>
      <th>Decision</th>
      <th>Review Date</th>
      <th>Remarks</th>
      <th>Action</th>
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
    <td><?php echo esc_html($req->sub_name); ?></td>
    <td><?php echo !empty($req->submission_date) ? date('d M Y, h:i A', strtotime($req->submission_date)) : '—'; ?></td>
    <td><?php echo $dec_badge; ?></td>
    <td><?php echo !empty($req->qa_review_date) ? date('d M Y, h:i A', strtotime($req->qa_review_date)) : '—'; ?></td>
    <td style="max-width:200px;font-size:13px;color:#555;"><?php echo esc_html($req->qa_remarks ?: '—'); ?></td>
    <td><a href="<?php echo esc_url(add_query_arg('qa_history_view', $req->id, get_permalink())); ?>" class="btn btn-view" style="background:#6f42c1;">View Details</a></td>
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

    $cnt_pending    = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status IN ('pending_manager','pending')");
    $cnt_approved   = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='approved'");
    $cnt_rejected   = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='rejected'");
    $cnt_completed  = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='completed'");
    // QA counts for this manager when nominated as QA engineer on requests
    $my_qa_pending  = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table} WHERE qa_stno=%s AND status='pending_qa' ORDER BY submission_date ASC",
        $emp->stno
    ));
    $cnt_my_qa_pending = count($my_qa_pending);
    $my_qa_reviewed = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table} WHERE qa_stno=%s AND qa_decision!='' ORDER BY qa_review_date DESC LIMIT 20",
        $emp->stno
    ));

    if ($view_id) {
        $req = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $view_id));
        if ($req): 
        $bc='badge-pending';
        if($req->status==='pending_qa')   $bc='badge-pending-qa';
        if($req->status==='qa_rejected')  $bc='badge-qa-rejected';
        if($req->status==='approved')     $bc='badge-approved';
        if($req->status==='rejected')     $bc='badge-rejected';
        if($req->status==='completed')    $bc='badge-completed';
        if($req->status==='in_testing')   $bc='badge-approved';

        // Manager logic:
        // 'pending'                          → Manager sees indenter form, gives decision
        // 'approved'/'rejected'/'completed'  → Read-only summary view
        $show_decision_form = ($req->status === 'pending_manager');
        $show_readonly      = in_array($req->status, ['approved','rejected','completed','in_testing']);
        $show_readonly      = in_array($req->status, ['approved','rejected','completed','in_testing']);

        // Pipeline steps
        $steps = uhv_get_extended_pipeline_steps($req);
        ?>
<div class="container">
<div class="role-indicator">MANAGER VIEW | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>
<div style="margin-bottom:25px;display:flex;gap:10px;align-items:center;">
  <a href="<?php echo get_permalink(); ?>" class="btn btn-primary">← Back to Dashboard</a>
  <?php uhv_print_button($req->id); ?>
  <?php uhv_history_button($req->id); ?>
</div>

<?php // Success/info messages
$uhv_msg_v = sanitize_text_field($_GET['uhv_msg'] ?? '');
if ($uhv_msg_v === 'decision_saved'): ?>
<div style="background:#d4edda;color:#155724;border:1px solid #c3e6cb;padding:14px 20px;margin-bottom:20px;border-radius:4px;">
  ✓ <strong>Decision saved. Request has been updated successfully.</strong>
</div>
<?php elseif ($uhv_msg_v === 'comment_required'): ?>
<div style="background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;padding:14px 20px;margin-bottom:20px;border-radius:4px;">
  ⚠ <strong>A comment/reason is mandatory when rejecting or sending back for review.</strong>
</div>
<?php endif; ?>

<div class="request-card">
  <div class="request-header">
    <div>
      <h2 style="margin:0;">TR No: <?php echo esc_html($req->test_requisition_no); ?></h2>
      <small style="color:#666;">Submitted: <?php echo date('d M Y, h:i A', strtotime($req->submission_date)); ?></small>
    </div>
    <span class="badge <?php echo $bc; ?>"><?php echo strtoupper(str_replace('_',' ',$req->status)); ?></span>
  </div>

  <!-- ══ LIVE PROGRESS PIPELINE ══ -->
  <h3 style="margin-top:0;">Live Progress Pipeline</h3>
  <?php uhv_pipeline($steps); ?>

  <?php if ($req->status === 'qa_rejected' && (int)$req->user_id === (int)$user->ID): ?>
  <div style="background:#fff3cd;border:2px solid #fd7e14;padding:18px 22px;margin:20px 0;border-radius:4px;">
    <strong style="color:#856404;font-size:15px;">&#9888; Your Request Was Returned by the QA Engineer</strong><br>
    <span style="font-size:14px;color:#555;display:block;margin-top:6px;">
      <strong>Remarks:</strong> <em><?php echo esc_html($req->qa_remarks ?: '—'); ?></em>
    </span>
    <div style="margin-top:14px;">
      <a href="<?php echo esc_url(add_query_arg(['mgr_action'=>'create_new','resume_draft'=>$req->id], get_permalink())); ?>"
         class="btn btn-draft">&#9998; Edit &amp; Resubmit</a>
    </div>
  </div>
  <?php endif; ?>

  <!-- ══ INDENTER REQUEST DETAILS (always visible, read-only) ══ -->
  <div style="background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:20px 25px;margin:20px 0;">
    <h3 style="margin-top:0;color:#343a40;font-size:16px;">📋 USER REQUEST DETAILS <span style="font-size:12px;font-weight:400;color:#6c757d;">(Read-Only)</span></h3>

    <table style="width:100%;border-collapse:collapse;font-size:14px;">
      <tr><th style="border:1px solid #ccc;padding:10px;background:#e9ecef;width:22%;">Test Object</th><td style="border:1px solid #ccc;padding:10px;"><?php echo esc_html($req->satellite_name); ?></td><th style="border:1px solid #ccc;padding:10px;background:#e9ecef;width:22%;">Test Type</th><td style="border:1px solid #ccc;padding:10px;"><?php echo esc_html(!empty($req->test_types) ? $req->test_types : $req->test_type); ?></td></tr>
      <tr><th style="border:1px solid #ccc;padding:10px;background:#e9ecef;">Test Required on</th><td colspan="3" style="border:1px solid #ccc;padding:10px;"><?php echo !empty($req->test_required_on) ? date('d M Y', strtotime($req->test_required_on)) : '—'; ?></td></tr>
      <tr><th style="border:1px solid #ccc;padding:10px;background:#e9ecef;">Subsystem Engineer</th><td style="border:1px solid #ccc;padding:10px;"><?php echo esc_html($req->sub_name . ' (' . $req->sub_stno . ')'); ?></td><th style="border:1px solid #ccc;padding:10px;background:#e9ecef;">Section / Division</th><td style="border:1px solid #ccc;padding:10px;"><?php echo esc_html(($req->sub_section ?: '—') . ' / ' . ($req->sub_division ?: '—')); ?></td></tr>
      <tr><th style="border:1px solid #ccc;padding:10px;background:#e9ecef;">Designation</th><td style="border:1px solid #ccc;padding:10px;"><?php echo esc_html($req->sub_designation ?: '—'); ?></td><th style="border:1px solid #ccc;padding:10px;background:#e9ecef;">Phone</th><td style="border:1px solid #ccc;padding:10px;"><?php echo esc_html($req->sub_phone ?: '—'); ?></td></tr>
      <?php if ($req->qa_exists === 'yes' && !empty($req->qa_name)): ?>
      <tr><th style="border:1px solid #ccc;padding:10px;background:#e9ecef;">QA / T&amp;E Engineer</th><td colspan="3" style="border:1px solid #ccc;padding:10px;"><?php echo esc_html($req->qa_name . ' (' . $req->qa_stno . ')'); ?><?php if(!empty($req->qa_designation)): ?> &mdash; <small style="color:#555;"><?php echo esc_html($req->qa_designation); ?></small><?php endif; ?><br><small style="color:#555;"><?php echo esc_html($req->qa_section); ?></small></td></tr>
      <?php endif; ?>
      <?php if (!empty($req->special_requirements)): ?>
      <tr><th style="border:1px solid #ccc;padding:10px;background:#e9ecef;vertical-align:top;">Special Requirements</th><td colspan="3" style="border:1px solid #ccc;padding:10px;background:#fff3cd;"><?php echo nl2br(esc_html($req->special_requirements)); ?></td></tr>
      <?php endif; ?>
    </table>
    <?php uhv_render_test_subforms_readonly($req); ?>
  <?php uhv_render_per_test_risk_readonly($req, 'Staff Risk Assessment (Per Test)'); ?>

    <?php if (!empty($req->qa_reviewer_name)): ?>
    <h3 style="margin-top:18px;font-size:15px;color:#343a40;">QA / T&amp;E Engineer Review</h3>
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
      <tr><th style="border:1px solid #ccc;padding:9px;background:#e9ecef;width:22%;">Reviewed By</th><td style="border:1px solid #ccc;padding:9px;"><?php echo esc_html($req->qa_reviewer_name ?: '—'); ?></td><th style="border:1px solid #ccc;padding:9px;background:#e9ecef;width:22%;">Review Date</th><td style="border:1px solid #ccc;padding:9px;"><?php echo !empty($req->qa_review_date) ? date('d M Y, h:i A', strtotime($req->qa_review_date)) : '—'; ?></td></tr>
      <tr><th style="border:1px solid #ccc;padding:9px;background:#e9ecef;">Decision</th><td colspan="3" style="border:1px solid #ccc;padding:9px;"><?php echo $req->qa_decision === 'accept' ? '<span style="color:#28a745;font-weight:600;">✓ Accepted</span>' : '<span style="color:#fd7e14;font-weight:600;">✗ Rejected</span>'; ?><?php if (!empty($req->qa_remarks)): ?> &mdash; <em style="font-size:13px;color:#555;"><?php echo esc_html($req->qa_remarks); ?></em><?php endif; ?></td></tr>
    </table>
    <?php endif; ?>
  </div><!-- /indenter details panel -->

  <?php if ($show_decision_form): ?>
  <!-- ══ STEP 1: MANAGER DECISION FORM ══ -->
  <div style="background:#fff8e1;border:2px solid #ffc107;border-radius:6px;padding:25px;margin:20px 0;">
    <h3 style="margin-top:0;color:#856404;font-size:16px;">⚖️ MANAGER DECISION</h3>
    <p style="color:#555;font-size:14px;margin:0 0 18px 0;">Review the user's request above. Fill the Pre-Test Assessment, enter your remarks, and choose an action. A comment is <strong>mandatory</strong> for Reject and Send for Review.</p>
    <form method="post" enctype="multipart/form-data">
      <?php wp_nonce_field('uhv_action','uhv_nonce'); ?>
      <input type="hidden" name="form_id" value="<?php echo $req->id; ?>">

      <!-- Overhaul: Section A is now read-only for Manager, as it was filled by Staff -->
      <div style="background:#f1f8e9;border:1px solid #2e7d32;padding:15px;margin-bottom:20px;border-radius:4px;">
        <h4 style="margin-top:0;color:#2e7d32;font-size:15px;">🔍 Staff Risk Assessment Result</h4>
        <?php uhv_render_per_test_risk_readonly($req, ''); ?>
        
        <div style="margin-top:10px;padding-top:10px;border-top:1px dashed #2e7d32;">
          <label style="font-weight:600;font-size:13px;color:#2e7d32;display:block;margin-bottom:5px;">Upload Final Risk Record (Optional):</label>
          <input type="file" name="risk_assessment_file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" style="font-size:12px;">
          <?php if (!empty($req->risk_form_file)): ?>
            <div style="font-size:11px;margin-top:4px;"><a href="<?php echo esc_url($req->risk_form_file); ?>" target="_blank">View Existing File</a></div>
          <?php endif; ?>
        </div>
      </div>

      <label style="font-weight:600;display:block;margin-bottom:6px;font-size:14px;">Manager Remarks / Comments <span style="color:#dc3545;">*</span> <span style="font-weight:400;color:#6c757d;font-size:12px;">(required for Reject / Send for Review)</span></label>
      <textarea name="manager_comment" rows="4" placeholder="Enter your remarks, decision rationale, or instructions for the user..." style="width:100%;border:1px solid #000;padding:12px;font-size:14px;font-family:inherit;border-radius:4px;resize:vertical;"><?php echo esc_textarea($req->manager_comment ?? ''); ?></textarea>
      <div style="margin-top:18px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
        <button type="submit" name="action_type" value="approve"
                style="padding:14px 36px;background:#28a745;color:#fff;border:none;cursor:pointer;font-weight:700;font-size:18px;border-radius:4px;letter-spacing:.5px;"
                onclick="return confirm('Approve this request? This will assign a Test Requisition Number.')">
          ✓ APPROVE
        </button>
        <button type="submit" name="action_type" value="reject"
                style="padding:14px 36px;background:#dc3545;color:#fff;border:none;cursor:pointer;font-weight:700;font-size:18px;border-radius:4px;letter-spacing:.5px;"
                onclick="return validateMgrComment(this)">
          ✗ REJECT
        </button>
        <button type="submit" name="action_type" value="recheck"
                style="padding:14px 36px;background:#fd7e14;color:#fff;border:none;cursor:pointer;font-weight:700;font-size:18px;border-radius:4px;letter-spacing:.5px;"
                onclick="return validateMgrComment(this)">
          ↩ RECHECK TO STAFF
        </button>
      </div>
    </form>
  </div>
  <script>
  function validateMgrComment(btn) {
    var ta = btn.closest('form').querySelector('[name="manager_comment"]');
    if (!ta.value.trim()) { alert('Please enter remarks/comments before ' + (btn.value==='reject'?'rejecting':'sending for review') + '.'); ta.focus(); return false; }
    return confirm('Are you sure you want to ' + (btn.value==='reject'?'REJECT this request?':'send this request BACK TO STAFF for recheck?'));
  }
  </script>



  <?php elseif ($show_readonly): ?>
  <!-- ══ READ-ONLY SUMMARY: approved/rejected/completed ══ -->
  <div style="background:<?php echo $req->status==='approved'?'#d4edda':($req->status==='rejected'?'#f8d7da':'#e2e3e5'); ?>;border:2px solid <?php echo $req->status==='approved'?'#28a745':($req->status==='rejected'?'#dc3545':'#6c757d'); ?>;border-radius:6px;padding:20px 25px;margin:20px 0;">
    <h3 style="margin-top:0;color:<?php echo $req->status==='approved'?'#155724':($req->status==='rejected'?'#721c24':'#343a40'); ?>;font-size:18px;">
      <?php echo $req->status==='approved'?'✓ APPROVED':($req->status==='rejected'?'✗ REJECTED':($req->status==='completed'?'✓ COMPLETED':'STATUS: '.strtoupper($req->status))); ?>
    </h3>
    <table style="width:100%;border-collapse:collapse;font-size:17px;">
      <tr><th style="border:1px solid #ccc;padding:12px;background:rgba(255,255,255,.6);width:22%;text-align:left;">Decided By</th><td style="border:1px solid #ccc;padding:12px;background:#fff;"><?php echo esc_html($req->reviewed_by ?: '—'); ?></td><th style="border:1px solid #ccc;padding:12px;background:rgba(255,255,255,.6);width:22%;text-align:left;">Decision Date</th><td style="border:1px solid #ccc;padding:12px;background:#fff;"><?php echo !empty($req->approval_date) ? date('d M Y, h:i A', strtotime($req->approval_date)) : '—'; ?></td></tr>
      <?php if (!empty($req->manager_comment)): ?>
      <tr><th style="border:1px solid #ccc;padding:12px;background:rgba(255,255,255,.6);vertical-align:top;text-align:left;">Comments</th><td colspan="3" style="border:1px solid #ccc;padding:12px;background:#fff;"><?php echo nl2br(esc_html($req->manager_comment)); ?></td></tr>
      <?php endif; ?>
    </table>
  </div>


  <?php endif; // end show_readonly ?>

  <?php
  // ── UHV STAFF FORM (read-only) – shown to manager whenever status is approved / in_testing / completed ──
  if (in_array($req->status, ['approved', 'in_testing', 'completed'])):
      $has_any_staff = !empty($req->test_started_datetime)
                    || !empty($req->specimen_collected_by_name)
                    || !empty($req->verification_closed_by_name);
  ?>
  <div style="margin-top:30px;border-top:3px solid #000;padding-top:25px;">

    <h3 style="margin-top:0;font-size:18px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      &#128203; UHV Staff Completion Form
      <?php if ($req->status === 'completed'): ?>
        <span class="badge badge-completed" style="font-size:14px;padding:6px 14px;">&#10003; COMPLETED</span>
      <?php elseif ($has_any_staff): ?>
        <span class="badge" style="font-size:14px;padding:6px 14px;background:#fd7e14;color:#fff;">&#9654; In Progress</span>
      <?php else: ?>
        <span class="badge" style="font-size:14px;padding:6px 14px;background:#e0e0e0;color:#555;">&#9203; Not Started</span>
      <?php endif; ?>
      <span style="font-size:14px;font-weight:400;color:#6c757d;">(Read-Only)</span>
    </h3>

    <?php if (!$has_any_staff && $req->status !== 'completed'): ?>
    <div style="background:#f8f9fa;border:1px solid #dee2e6;padding:18px 22px;border-radius:4px;color:#666;font-size:14px;">
      &#9203; UHV staff have not started filling this form yet.
    </div>

    <?php else: ?>

    <?php if (!empty($req->draft_saved_at)): ?>
    <div class="draft-notice">
      <strong>&#128203; Draft Saved:</strong> Last saved by
      <strong><?php echo esc_html($req->draft_saved_by ?? 'Unknown'); ?></strong> on
      <strong><?php echo date('d M Y, h:i A', strtotime($req->draft_saved_at)); ?></strong>
    </div>
    <?php endif; ?>

    <?php if ($req->status === 'completed' && !empty($req->completion_date)): ?>
    <div style="background:#d4edda;border-left:4px solid #28a745;padding:12px 18px;margin-bottom:18px;border-radius:4px;font-size:14px;">
      &#10003; <strong>Form completed and submitted on <?php echo date('d M Y, h:i A', strtotime($req->completion_date)); ?></strong>
    </div>
    <?php endif; ?>

    <!-- SECTION B -->
    <h4 style="font-size:18px;font-weight:700;margin:25px 0 12px 0;padding-bottom:8px;border-bottom:2px solid #000;text-transform:uppercase;letter-spacing:.5px;color:#343a40;">
      Section B &mdash; Test Execution Details
    </h4>
    <table style="width:100%;border-collapse:collapse;font-size:17px;margin-bottom:22px;">
      <tr>
        <th style="border:1px solid #000;padding:11px;background:#f5f5f5;width:42%;text-align:left;">Test Started on</th>
        <td style="border:1px solid #000;padding:11px;"><?php echo !empty($req->test_started_datetime) ? date('d M Y, h:i A', strtotime($req->test_started_datetime)) : '<em style="color:#aaa;">&mdash;</em>'; ?></td>
      </tr>
      <tr>
        <th style="border:1px solid #000;padding:11px;background:#f5f5f5;text-align:left;">Test Completed on</th>
        <td style="border:1px solid #000;padding:11px;"><?php echo !empty($req->test_completed_datetime) ? date('d M Y, h:i A', strtotime($req->test_completed_datetime)) : '<em style="color:#aaa;">&mdash;</em>'; ?></td>
      </tr>
      <tr>
        <th style="border:1px solid #000;padding:11px;background:#f5f5f5;text-align:left;">Test Duration</th>
        <td style="border:1px solid #000;padding:11px;"><?php echo esc_html($req->test_duration ?: '&mdash;'); ?></td>
      </tr>
      <tr>
        <th style="border:1px solid #000;padding:11px;background:#f5f5f5;text-align:left;">Completed On-Time?</th>
        <td style="border:1px solid #000;padding:11px;"><?php echo esc_html($req->test_on_time ?: '&mdash;'); ?></td>
      </tr>

      <tr>
        <th style="border:1px solid #000;padding:11px;background:#f5f5f5;text-align:left;">Test Code</th>
        <td style="border:1px solid #000;padding:11px;"><?php echo esc_html($req->test_code ?: '&mdash;'); ?></td>
      </tr>
    </table>

    <!-- SECTION C -->
    <h4 style="font-size:18px;font-weight:700;margin:25px 0 12px 0;padding-bottom:8px;border-bottom:2px solid #000;text-transform:uppercase;letter-spacing:.5px;color:#343a40;">
      Section C &mdash; Specimen Collection &amp; Closure
    </h4>
    <table style="width:100%;border-collapse:collapse;font-size:17px;">
      <tr>
        <th colspan="2" style="border:1px solid #000;padding:11px;background:#333;color:#fff;font-weight:600;">Test Specimen Collected By</th>
      </tr>
      <tr>
        <th style="border:1px solid #000;padding:11px;background:#f5f5f5;width:42%;text-align:left;">Name</th>
        <td style="border:1px solid #000;padding:11px;"><?php echo esc_html($req->specimen_collected_by_name ?: '&mdash;'); ?></td>
      </tr>
      <tr>
        <th style="border:1px solid #000;padding:11px;background:#f5f5f5;text-align:left;">Signature / Initials</th>
        <td style="border:1px solid #000;padding:11px;"><?php echo esc_html($req->specimen_collected_by_sig ?: '&mdash;'); ?></td>
      </tr>
      <tr>
        <th colspan="2" style="border:1px solid #000;padding:11px;background:#333;color:#fff;font-weight:600;">Verification &amp; Requisition Closed By</th>
      </tr>
      <tr>
        <th style="border:1px solid #000;padding:11px;background:#f5f5f5;text-align:left;">Name</th>
        <td style="border:1px solid #000;padding:11px;"><?php echo esc_html($req->verification_closed_by_name ?: '&mdash;'); ?></td>
      </tr>
      <tr>
        <th style="border:1px solid #000;padding:11px;background:#f5f5f5;text-align:left;">Signature / Initials</th>
        <td style="border:1px solid #000;padding:11px;"><?php echo esc_html($req->verification_closed_by_sig ?: '&mdash;'); ?></td>
      </tr>
    </table>

    <?php endif; // has_any_staff or completed ?>
  </div>
  <?php endif; // approved/in_testing/completed ?>

</div><!-- /request-card -->
</div><!-- /container -->
<?php
        else: echo "<p style='text-align:center;color:#dc3545;padding:50px;'>Request not found or already processed.</p>"; endif;

    } elseif ($mgr_action === 'create_new') {
        // Load specific draft/qa_rejected if resume_draft param given, otherwise blank form
        $resume_id = intval($_GET['resume_draft'] ?? 0);
        if ($resume_id) {
            $existing_draft = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id=%d AND user_id=%d AND status IN ('draft_indenter','qa_rejected','rejected','recheck_indenter')",
                $resume_id, $user->ID
            ));
        } else {
            $existing_draft = null;
        } ?>
<div class="form-container">
<div class="role-indicator">USER VIEW | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>
<div style="margin-bottom:25px;"><a href="<?php echo get_permalink(); ?>" class="btn btn-primary">&larr; Back to Dashboard</a></div>
<h1>New Request Submission</h1>
<?php
$_uhv_errs = get_transient('uhv_errors_'.$user->ID);
if (!empty($_uhv_errs)) {
    delete_transient('uhv_errors_'.$user->ID);
    echo "<div style='background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;padding:14px 20px;margin-bottom:20px;border-radius:4px;font-size:15px;'>";
    echo "<strong>Please fix the following errors:</strong><ul style='margin:8px 0 0 20px;padding:0;'>";
    foreach ($_uhv_errs as $e) echo "<li>".esc_html($e)."</li>";
    echo "</ul></div>";
}
?>
<?php uhv_request_form($emp, $existing_draft, admin_url('admin-ajax.php')); ?>
</div>
<?php

    } elseif ($mgr_action === 'my_requests') {
        $my = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE user_id=%d ORDER BY submission_date DESC", $user->ID)); ?>
<div class="container">
<div class="role-indicator">MANAGER VIEW | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>
<?php mgr_stat_cards($cnt_pending,$cnt_approved,$cnt_rejected,$cnt_completed,$cnt_my_qa_pending); ?>
<?php mgr_tabs('my_requests', $cnt_pending, $cnt_my_qa_pending); ?>
<h1>My Submitted Requests</h1>
<?php if(empty($my)): ?><div style="text-align:center;padding:80px;color:#666;border:2px solid #ddd;background:#f9f9f9;border-radius:8px;"><h3 style="margin:0 0 10px 0;font-size:18px;color:#333;">NO REQUESTS SUBMITTED</h3><p style="margin:0;font-size:15px;">Click "+ NEW REQUEST" to create one.</p></div>
<?php else: ?>
<table class="list-table" id="table-mgr-my-requests">
  <thead><tr><th>TR No.</th><th>Test Object</th><th>Submitted Date</th><th>Status</th><th>Action</th></tr></thead>
  <tbody>
  <?php foreach($my as $req):
    $bc='badge-pending';
    if($req->status==='draft_indenter') $bc='';
    if($req->status==='pending_qa')  $bc='badge-pending-qa';
    if($req->status==='qa_rejected') $bc='badge-qa-rejected';
    if($req->status==='approved')    $bc='badge-approved';
    if($req->status==='rejected')    $bc='badge-rejected';
    if($req->status==='completed')   $bc='badge-completed';
    $tr_display = (strpos($req->test_requisition_no,'PENDING-')===0||strpos($req->test_requisition_no,'DRAFT-')===0)
        ? '<em style="color:#999;font-size:12px;">—</em>'
        : '<strong>'.esc_html($req->test_requisition_no).'</strong>';
    $status_label = ($req->status==='recheck_indenter') ? 'SENT BACK' : strtoupper(str_replace('_',' ',$req->status));
  ?>
  <tr>
    <td><?php echo $tr_display; ?></td>
    <td><?php echo esc_html($req->satellite_name ?: '(Untitled)'); ?></td>
    <td><?php echo date('d M Y, h:i A', strtotime($req->submission_date)); ?></td>
    <td>
      <?php if($req->status === 'draft_indenter'): ?>
        <span class="badge" style="background:#6c757d;color:#fff;font-size:11px;padding:4px 10px;">&#128203; DRAFT</span>
      <?php else: ?>
        <span class="badge <?php echo $bc; ?>"><?php echo $status_label; ?></span>
      <?php endif; ?>
    </td>
    <td style="white-space:nowrap;">
      <?php if($req->status === 'draft_indenter'): ?>
        <a href="<?php echo esc_url(add_query_arg(['mgr_action'=>'create_new','resume_draft'=>$req->id], get_permalink())); ?>"
           class="btn btn-draft">&#128203; Continue Draft</a>
      <?php elseif(in_array($req->status, ['qa_rejected','rejected'])): ?>
        <a href="<?php echo esc_url(add_query_arg('view_id',$req->id)); ?>" class="btn btn-view" style="margin-right:4px;">View Details</a>
        <a href="<?php echo esc_url(add_query_arg(['mgr_action'=>'create_new','resume_draft'=>$req->id], get_permalink())); ?>"
           class="btn btn-draft">&#9998; Resubmit</a>
      <?php else: ?>
        <a href="<?php echo esc_url(add_query_arg('view_id',$req->id)); ?>" class="btn btn-view">View Details</a>
      <?php endif; ?>
    </td>
  </tr>
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
<?php mgr_stat_cards($cnt_pending,$cnt_approved,$cnt_rejected,$cnt_completed,$cnt_my_qa_pending); ?>
<?php mgr_tabs('progress', $cnt_pending, $cnt_my_qa_pending); ?>
<h1>Track Post-Approval Progress</h1>

<?php if(empty($in_prog)): ?>
<div style="text-align:center;padding:40px;color:#666;border:2px solid #ddd;margin-bottom:30px;"><h3 style="margin:0;">No requests currently in testing</h3></div>
<?php else: ?>
<h3 style="margin-top:0;">Currently In Testing (<?php echo count($in_prog); ?>)</h3>
<?php foreach($in_prog as $req):
    $etf_step='Awaiting UHV';
    if(!empty($req->start_user)) $etf_step='🟡 Test Running';
    if(!empty($req->end_user))   $etf_step='🟠 Test Completed';
?>
<div style="border:1px solid #ddd;padding:18px 22px;margin-bottom:12px;background:#fff;display:flex;justify-content:space-between;align-items:center;border-radius:4px;">
  <div>
    <div style="font-weight:600;font-size:15px;"><?php echo esc_html($req->test_requisition_no.' — '.$req->satellite_name); ?></div>
    <div style="font-size:13px;color:#555;">Approved: <?php echo !empty($req->approval_date)?date('d M Y',strtotime($req->approval_date)):'—'; ?> | Status: <strong><?php echo $etf_step; ?></strong></div>
  </div>
  <a href="<?php echo esc_url(get_permalink().'?mgr_action=progress&prog_id='.$req->id); ?>" class="btn btn-info">Details →</a>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php if(!empty($completed)): ?>
<h3>Recently Completed (Last 10)</h3>
<table class="list-table" id="table-mgr-completed-recent" style="margin-top:0;">
  <thead><tr><th>TR No.</th><th>Test Object</th><th>Completed</th><th>Action</th></tr></thead>
  <tbody>
  <?php foreach($completed as $req):?>
  <tr>
    <td><strong><?php echo esc_html($req->test_requisition_no); ?></strong></td>
    <td><?php echo esc_html($req->satellite_name); ?></td>
    <td><?php echo !empty($req->completion_date)?date('d M Y',strtotime($req->completion_date)):'—'; ?></td>
    <td><a href="<?php echo esc_url(add_query_arg('view_id', $req->id, get_permalink())); ?>" class="btn btn-view">View Details</a></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
</div>
<?php

    } elseif ($mgr_action === 'pending') {
        $pending = $wpdb->get_results("SELECT * FROM {$table} WHERE status IN ('pending_manager','pending') ORDER BY submission_date DESC"); ?>
<div class="container">
<div class="role-indicator">MANAGER VIEW | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>
<?php mgr_stat_cards($cnt_pending,$cnt_approved,$cnt_rejected,$cnt_completed,$cnt_my_qa_pending); ?>
<?php mgr_tabs('pending', $cnt_pending, $cnt_my_qa_pending); ?>
<h1>Pending Approvals</h1>
<?php if(empty($pending)): ?><div style="text-align:center;padding:60px;color:#666;border:2px solid #ddd;"><h3 style="margin:0;">No pending requests</h3></div>
<?php else: ?>
<table class="list-table" id="table-mgr-pending">
  <thead><tr><th>TR No.</th><th>Test Object</th><th>Submitted By</th><th>Submitted Date</th><th>Action</th></tr></thead>
  <tbody>
  <?php foreach($pending as $req):?>
  <?php 
    $tr_pend = (strpos($req->test_requisition_no,'PENDING-')===0||strpos($req->test_requisition_no,'DRAFT-')===0)
        ? '<em style="color:#999;font-size:12px;">No TR yet</em>'
        : '<strong>'.esc_html($req->test_requisition_no).'</strong>';
  ?>
  <tr><td><?php echo $tr_pend; ?></td><td><?php echo esc_html($req->satellite_name); ?></td><td><?php echo esc_html($req->sub_name); ?></td><td><?php echo date('d M Y, h:i A', strtotime($req->submission_date)); ?></td><td><a href="<?php echo esc_url(add_query_arg('view_id',$req->id)); ?>" class="btn btn-view">View & Approve</a></td></tr>
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
<?php mgr_stat_cards($cnt_pending,$cnt_approved,$cnt_rejected,$cnt_completed,$cnt_my_qa_pending); ?>
<?php mgr_tabs('in_testing', $cnt_pending, $cnt_my_qa_pending); ?>
<h1>In Testing (<?php echo count($rows); ?>)</h1>
<?php if(empty($rows)): ?>
<div style="text-align:center;padding:60px;color:#666;border:2px solid #ddd;border-radius:6px;"><h3 style="margin:0;">No requests currently in testing</h3></div>
<?php else: ?>
<table class="list-table" id="table-mgr-in-testing">
  <thead><tr><th>TR No.</th><th>Test Object</th><th>Submitted By</th><th>Submitted Date</th><th>Approved On</th><th>Test Required On</th><th>Action</th></tr></thead>
  <tbody>
  <?php foreach($rows as $req): ?>
  <tr>
    <td><strong><?php echo esc_html($req->test_requisition_no); ?></strong></td>
    <td><?php echo esc_html($req->satellite_name); ?></td>
    <td><?php echo esc_html($req->sub_name); ?></td>
    <td><?php echo !empty($req->submission_date) ? date('d M Y, h:i A', strtotime($req->submission_date)) : '—'; ?></td>
    <td><?php echo !empty($req->approval_date) ? date('d M Y', strtotime($req->approval_date)) : '—'; ?></td>
    <td><?php echo !empty($req->test_required_on) ? date('d M Y', strtotime($req->test_required_on)) : '—'; ?></td>
    <td><a href="?view_id=<?php echo $req->id; ?>" class="btn btn-view">VIEW DETAILS</a></td>
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
<?php mgr_stat_cards($cnt_pending,$cnt_approved,$cnt_rejected,$cnt_completed,$cnt_my_qa_pending); ?>
<?php mgr_tabs('rejected_list', $cnt_pending, $cnt_my_qa_pending); ?>
<h1>Rejected Requests (<?php echo count($rows); ?>)</h1>
<?php if(empty($rows)): ?>
<div style="text-align:center;padding:60px;color:#666;border:2px solid #ddd;border-radius:6px;"><h3 style="margin:0;">No rejected requests</h3></div>
<?php else: ?>
<table class="list-table" id="table-mgr-rejected">
  <thead><tr><th>TR No.</th><th>Test Object</th><th>Submitted By</th><th>Submitted Date</th><th>Rejected On</th><th>Manager Comments</th><th>Action</th></tr></thead>
  <tbody>
  <?php foreach($rows as $req): ?>
  <tr>
    <td><strong><?php echo esc_html($req->test_requisition_no); ?></strong></td>
    <td><?php echo esc_html($req->satellite_name); ?></td>
    <td><?php echo esc_html($req->sub_name); ?></td>
    <td><?php echo !empty($req->submission_date) ? date('d M Y, h:i A', strtotime($req->submission_date)) : '—'; ?></td>
    <td><?php echo !empty($req->approval_date) ? date('d M Y', strtotime($req->approval_date)) : '—'; ?></td>
    <td style="max-width:220px;font-size:13px;color:#555;"><?php echo esc_html($req->manager_comment ?: '—'); ?></td>
    <td><a href="?view_id=<?php echo $req->id; ?>" class="btn btn-view">VIEW DETAILS</a></td>
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
<?php mgr_stat_cards($cnt_pending,$cnt_approved,$cnt_rejected,$cnt_completed,$cnt_my_qa_pending); ?>
<?php mgr_tabs('completed_list', $cnt_pending, $cnt_my_qa_pending); ?>
<h1>Completed Tests (<?php echo count($rows); ?>)</h1>
<?php if(empty($rows)): ?>
<div style="text-align:center;padding:60px;color:#666;border:2px solid #ddd;border-radius:6px;"><h3 style="margin:0;">No completed tests yet</h3></div>
<?php else: ?>
<table class="list-table" id="table-mgr-completed-full">
  <thead><tr><th>TR No.</th><th>Test Object</th><th>Submitted By</th><th>Submitted Date</th><th>Approved On</th><th>Completed On</th><th>Action</th></tr></thead>
  <tbody>
  <?php foreach($rows as $req): ?>
  <tr>
    <td><strong><?php echo esc_html($req->test_requisition_no); ?></strong></td>
    <td><?php echo esc_html($req->satellite_name); ?></td>
    <td><?php echo esc_html($req->sub_name); ?></td>
    <td><?php echo !empty($req->submission_date) ? date('d M Y, h:i A', strtotime($req->submission_date)) : '—'; ?></td>
    <td><?php echo !empty($req->approval_date) ? date('d M Y', strtotime($req->approval_date)) : '—'; ?></td>
    <td><?php echo !empty($req->completion_date) ? date('d M Y', strtotime($req->completion_date)) : '—'; ?></td>
    <td><a href="?view_id=<?php echo $req->id; ?>" class="btn btn-view">VIEW DETAILS</a></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
</div>
<?php

    } elseif ($mgr_action === 'qa_review') {
        // ── Manager acting as nominated QA engineer ──────────────────
        $qa_view_id = intval($_GET['qa_view'] ?? 0);
        $uhv_msg_qa = sanitize_text_field($_GET['uhv_msg'] ?? '');
        if (isset($_GET['qa_history_view']) && intval($_GET['qa_history_view']) > 0) {
            // ── Read-only history detail ──────────────────────────────
            $qa_hist_req = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id=%d AND qa_stno=%s",
                intval($_GET['qa_history_view']), $emp->stno
            ));
            if ($qa_hist_req) {
                $back = add_query_arg('mgr_action','qa_review', remove_query_arg(['qa_history_view'], get_permalink()));
                echo '<div class="container">';
                echo '<div class="role-indicator">MANAGER VIEW — QA REVIEW HISTORY | '.esc_html($emp->name.' ('.$emp->stno.')').'</div>';
                mgr_stat_cards($cnt_pending,$cnt_approved,$cnt_rejected,$cnt_completed,$cnt_my_qa_pending);
                mgr_tabs('qa_review', $cnt_pending, $cnt_my_qa_pending);
                uhv_qa_history_detail($qa_hist_req, $back, '', false);
                echo '</div>';
            } else {
                echo "<p style='text-align:center;color:#dc3545;padding:50px;'>Record not found.</p>";
            }
        } elseif ($qa_view_id) {
            // Individual QA review form
            $req = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id=%d AND qa_stno=%s AND status='pending_qa'",
                $qa_view_id, $emp->stno
            ));
            if ($req): ?>
<div class="container">
<div class="role-indicator">MANAGER VIEW — QA REVIEW | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>
<div style="margin-bottom:20px;display:flex;gap:10px;flex-wrap:wrap;">
  <a href="<?php echo esc_url(add_query_arg('mgr_action','qa_review', remove_query_arg(['qa_view','uhv_msg'], get_permalink()))); ?>" class="btn btn-primary">&larr; Back to QA Review List</a>
  <a href="<?php echo get_permalink(); ?>" class="btn btn-primary" style="background:#555;">&larr; My Dashboard</a>
</div>
<?php mgr_stat_cards($cnt_pending,$cnt_approved,$cnt_rejected,$cnt_completed,$cnt_my_qa_pending); ?>
<?php mgr_tabs('qa_review', $cnt_pending, $cnt_my_qa_pending); ?>
<div class="request-card" style="margin-top:20px;">
  <div class="request-header">
    <div>
      <h2 style="margin:0;font-size:22px;">QA Review &mdash; <?php echo esc_html($req->test_requisition_no); ?></h2>
      <small style="color:#666;"><?php echo esc_html($req->satellite_name); ?></small><br>
      <small style="color:#666;">Submitted by <strong><?php echo esc_html($req->sub_name); ?></strong> on <?php echo date('d M Y, h:i A', strtotime($req->submission_date)); ?></small>
    </div>
    <span class="badge badge-pending-qa">PENDING QA REVIEW</span>
  </div>
  <div style="background:#faf7ff;border-left:4px solid #6f42c1;padding:12px 17px;margin-bottom:20px;border-radius:4px;font-size:14px;color:#4a148c;">
    &#128100; You have been <strong>nominated as QA / T&amp;E Engineer</strong> for this test request.
  </div>
  <h3>Submitter Details</h3>
  <table>
    <tr><th style="width:20%">Name</th><td style="width:30%"><?php echo esc_html($req->sub_name); ?></td><th style="width:20%">Staff No.</th><td><?php echo esc_html($req->sub_stno); ?></td></tr>
    <tr><th>Designation</th><td><?php echo esc_html($req->sub_designation?:'&mdash;'); ?></td><th>Phone</th><td><?php echo esc_html($req->sub_phone?:'&mdash;'); ?></td></tr>
    <tr><th>Section / Division</th><td colspan="3"><?php echo esc_html(($req->sub_section?:'&mdash;').' / '.($req->sub_division?:'&mdash;')); ?></td></tr>
  </table>
  <h3>Test Object Details</h3>
  <table>
    <tr><th>Test Required on</th><td colspan="3"><?php echo !empty($req->test_required_on)?date('d M Y',strtotime($req->test_required_on)):'&mdash;'; ?></td></tr>
    <?php if (!empty($req->special_requirements)): ?><tr><th>Special Requirements</th><td colspan="3" style="background:#fff3cd;"><?php echo nl2br(esc_html($req->special_requirements)); ?></td></tr><?php endif; ?>
  </table>
  <?php uhv_render_test_subforms_readonly($req); ?>
  <?php uhv_render_per_test_risk_readonly($req, 'Staff Risk Assessment (Per Test)'); ?>

  <form method="post" style="margin-top:30px;">
    <?php wp_nonce_field('uhv_action','uhv_nonce'); ?>
    <input type="hidden" name="form_id" value="<?php echo intval($req->id); ?>">
    <div style="border:2px solid #6f42c1;padding:28px;background:#faf7ff;border-radius:6px;">
      <h4 style="margin:0 0 20px;font-size:16px;font-weight:600;text-transform:uppercase;letter-spacing:.8px;color:#6f42c1;">&#9876; QA / T&amp;E Engineer Review</h4>
      <table style="width:100%;border-collapse:collapse;margin-bottom:20px;">
        <tr><td style="border:1px solid #ddd;padding:12px;background:#f5f5f5;font-weight:600;width:25%;">Reviewer Name</td><td style="border:1px solid #ddd;padding:12px;"><input class="block" value="<?php echo esc_attr($emp->name); ?>" readonly style="background:#f5f5f5;max-width:350px;"></td></tr>
        <tr><td style="border:1px solid #ddd;padding:12px;background:#f5f5f5;font-weight:600;">Review Date</td><td style="border:1px solid #ddd;padding:12px;"><input class="block" value="<?php echo date('d M Y, h:i A'); ?>" readonly style="background:#f5f5f5;max-width:250px;"></td></tr>
        <tr>
          <td style="border:1px solid #ddd;padding:14px;background:#f5f5f5;font-weight:600;">Remarks / Observations <span style="color:#dc3545;">*</span></td>
          <td style="border:1px solid #ddd;padding:12px;">
            <textarea name="qa_remarks" rows="4" placeholder="Enter remarks or reason for rejection..." required style="width:100%;border:1px solid #6f42c1;padding:12px;font-size:14px;font-family:inherit;resize:vertical;border-radius:4px;"></textarea>
          </td>
        </tr>
      </table>
      <div style="display:flex;gap:16px;flex-wrap:wrap;">
        <button type="submit" name="qa_decision" value="accept" class="btn btn-approve" onclick="return confirm('Accept and forward to Manager queue?')">&#10003; ACCEPT &amp; FORWARD TO APPROVAL</button>
        <button type="submit" name="qa_decision" value="reject" class="btn btn-reject" onclick="return confirm('Reject and return to User?')">&#10007; REJECT &amp; RETURN TO USER</button>
      </div>
      <p style="font-size:13px;color:#666;margin-top:14px;"><strong>Accept</strong> &rarr; moves to Pending Approval queue &nbsp;|&nbsp; <strong>Reject</strong> &rarr; returned to User with your remarks</p>
    </div>
  </form>
</div>
</div>
<?php
            else:
                echo "<p style='text-align:center;color:#dc3545;padding:50px;'>Request not found, already reviewed, or you are not the nominated QA for this request.</p>";
            endif;
        } else {
            // QA review list
?>
<div class="container">
<div class="role-indicator">MANAGER VIEW — MY QA REVIEWS | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>
<?php if ($uhv_msg_qa === 'qa_accepted'): ?>
<div style="background:#d4edda;color:#155724;border:1px solid #c3e6cb;padding:14px 20px;margin-bottom:20px;border-radius:4px;">&#10003; <strong>Accepted &amp; forwarded to the approval queue.</strong></div>
<?php elseif ($uhv_msg_qa === 'qa_rejected'): ?>
<div style="background:#fff3cd;color:#856404;border:1px solid #ffc107;padding:14px 20px;margin-bottom:20px;border-radius:4px;">&#10003; <strong>Rejected and returned to the user with your remarks.</strong></div>
<?php endif; ?>
<?php mgr_stat_cards($cnt_pending,$cnt_approved,$cnt_rejected,$cnt_completed,$cnt_my_qa_pending); ?>
<?php mgr_tabs('qa_review', $cnt_pending, $cnt_my_qa_pending); ?>
<div style="background:#faf7ff;border:2px solid #6f42c1;padding:14px 20px;margin:20px 0;border-radius:6px;font-size:14px;">
  &#128100; You are the <strong>nominated QA / T&amp;E Engineer</strong> on the requests below (in addition to your manager role). Review and accept or reject each one.
</div>
<!-- Pending QA Reviews -->
<h2 style="font-size:18px;margin:25px 0 12px;">&#9881; Awaiting Your QA Review (<?php echo count($my_qa_pending); ?>)</h2>
<?php if (empty($my_qa_pending)): ?>
<div style="text-align:center;padding:40px;color:#666;border:2px solid #ddd;background:#f9f9f9;border-radius:8px;margin-bottom:30px;">
  <h3 style="margin:0 0 8px;">No Pending QA Reviews</h3>
  <p style="margin:0;font-size:14px;">You have no requests awaiting your QA review at this time.</p>
</div>
<?php else: ?>
<table class="list-table" id="table-mgr-qa-pending" style="margin-bottom:35px;">
  <thead><tr><th>TR No.</th><th>Test Object</th><th>Submitted By</th><th>Submitted Date</th><th>Action</th></tr></thead>
  <tbody>
  <?php foreach ($my_qa_pending as $_qr): ?>
  <tr>
    <td><strong><?php echo esc_html($_qr->test_requisition_no); ?></strong></td>
    <td><?php echo esc_html($_qr->satellite_name); ?></td>
    <td><?php echo esc_html($_qr->sub_name); ?></td>
    <td><?php echo date('d M Y, h:i A', strtotime($_qr->submission_date)); ?></td>
    <td><a href="<?php echo esc_url(add_query_arg(['mgr_action'=>'qa_review','qa_view'=>$_qr->id], get_permalink())); ?>" class="btn btn-view" style="background:#6f42c1;">Review Request</a></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<!-- QA Review History -->
<?php if (!empty($my_qa_reviewed)): ?>
<h2 style="font-size:18px;margin:25px 0 12px;">&#128203; My QA Review History</h2>
<table class="list-table" id="table-mgr-qa-history">
  <thead><tr><th>TR No.</th><th>Test Object</th><th>Submitted By</th><th>Submitted Date</th><th>Decision</th><th>Review Date</th><th>Remarks</th><th>Action</th></tr></thead>
  <tbody>
  <?php foreach ($my_qa_reviewed as $_qrh):
    $_dec = ($_qrh->qa_decision==='accept')
        ? '<span class="badge badge-approved" style="font-size:11px;padding:4px 10px;">&#10003; ACCEPTED</span>'
        : '<span class="badge badge-qa-rejected" style="font-size:11px;padding:4px 10px;">&#10007; REJECTED</span>';
    $_tr = (strpos($_qrh->test_requisition_no,'PENDING-')===0||strpos($_qrh->test_requisition_no,'DRAFT-')===0)
        ? '<em style="color:#999;font-size:12px;">Not assigned</em>'
        : '<strong>'.esc_html($_qrh->test_requisition_no).'</strong>';
  ?>
  <tr>
    <td><?php echo $_tr; ?></td>
    <td><?php echo esc_html($_qrh->satellite_name); ?></td>
    <td><?php echo esc_html($_qrh->sub_name); ?></td>
    <td><?php echo !empty($_qrh->submission_date) ? date('d M Y, h:i A', strtotime($_qrh->submission_date)) : '—'; ?></td>
    <td><?php echo $_dec; ?></td>
    <td><?php echo !empty($_qrh->qa_review_date)?date('d M Y, h:i A',strtotime($_qrh->qa_review_date)):'&mdash;'; ?></td>
    <td style="max-width:200px;font-size:13px;color:#555;"><?php echo esc_html($_qrh->qa_remarks?:'&mdash;'); ?></td>
    <td><a href="<?php echo esc_url(add_query_arg(['mgr_action'=>'qa_review','qa_history_view'=>$_qrh->id], get_permalink())); ?>" class="btn btn-view" style="background:#6f42c1;">View Details</a></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
</div>
<?php
        } // end qa_view_id else

    } else {
        $recent_all  = $wpdb->get_results("SELECT * FROM {$table} ORDER BY submission_date DESC LIMIT 6");
        $my_drafts   = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d AND status = 'draft_indenter' ORDER BY id DESC",
            $user->ID
        ));
?>
<div class="container">
<div class="role-indicator">MANAGER VIEW | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>
<?php $uhv_msg = sanitize_text_field($_GET['uhv_msg'] ?? '');
if ($uhv_msg === 'approved'): ?>
<div style="background:#d4edda;color:#155724;border:1px solid #c3e6cb;padding:14px 20px;margin-bottom:20px;border-radius:4px;">&#10003; <strong>Request approved successfully.</strong> Test Requisition Number has been assigned and UHV staff have been notified.</div>
<?php elseif ($uhv_msg === 'rejected'): ?>
<div style="background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;padding:14px 20px;margin-bottom:20px;border-radius:4px;">&#10003; <strong>Request rejected.</strong> The user has been notified.</div>
<?php elseif ($uhv_msg === 'recheck_sent'): ?>
<div style="background:#fff3cd;color:#856404;border:1px solid #ffc107;padding:14px 20px;margin-bottom:20px;border-radius:4px;">&#8617; <strong>Request sent back to the User for review/editing.</strong> They have been notified.</div>
<?php elseif ($uhv_msg === 'submitted'): ?>
<div style="background:#d4edda;color:#155724;border:1px solid #c3e6cb;padding:16px 22px;margin-bottom:25px;border-radius:4px;font-size:15px;">
  &#10003; <strong>Request submitted successfully.</strong> A Test Requisition Number will be assigned upon manager approval.
</div>
<?php elseif ($uhv_msg === 'draft_saved'): ?>
<div style="background:#d1ecf1;color:#0c5460;border:1px solid #bee5eb;padding:16px 22px;margin-bottom:25px;border-radius:4px;font-size:15px;">
  &#10003; <strong>Draft saved successfully.</strong> You can continue editing it from the "My Requests" tab.
</div>
<?php endif; ?>
<?php mgr_stat_cards($cnt_pending,$cnt_approved,$cnt_rejected,$cnt_completed,$cnt_my_qa_pending); ?>
<?php mgr_tabs('dashboard', $cnt_pending, $cnt_my_qa_pending); ?>

<?php if (!empty($my_drafts)): ?>
<h3 style="margin-bottom:12px;">&#128203; My Saved Drafts</h3>
<table class="list-table" id="table-mgr-drafts" style="margin-bottom:35px;">
  <thead><tr><th>Test Object</th><th>Last Saved</th><th>Action</th></tr></thead>
  <tbody>
  <?php foreach($my_drafts as $dr): ?>
  <tr style="background:#fffdf0;">
    <td><?php echo esc_html($dr->satellite_name ?: '(Untitled)'); ?></td>
    <td><?php echo !empty($dr->indenter_draft_saved_at) ? date('d M Y, h:i A', strtotime($dr->indenter_draft_saved_at)) : '—'; ?></td>
    <td>
      <a href="<?php echo esc_url(add_query_arg(['mgr_action'=>'create_new','resume_draft'=>$dr->id], get_permalink())); ?>" class="btn btn-draft" style="margin-right:8px;">Continue Draft</a>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<div style="margin-top:10px;">
  <h3 style="margin-top:0;">Recent Submissions</h3>
  <?php if(empty($recent_all)): ?><p style="color:#666;">No submissions yet.</p>
  <?php else: ?>
  <table class="list-table" id="table-mgr-recent" style="margin-top:0;">
    <thead><tr><th>TR No.</th><th>Test Object</th><th>Submitted By</th><th>Status</th><th>Submitted Date</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach($recent_all as $req): $bc='badge-pending';if($req->status==='approved')$bc='badge-approved';if($req->status==='rejected')$bc='badge-rejected';if($req->status==='completed')$bc='badge-completed'; ?>
    <tr><td><strong><?php echo esc_html($req->test_requisition_no); ?></strong></td><td><?php echo esc_html($req->satellite_name); ?></td><td><?php echo esc_html($req->sub_name ?: '—'); ?></td><td><span class="badge <?php echo $bc; ?>" style="padding:4px 10px;font-size:11px;"><?php echo strtoupper($req->status); ?></span></td><td style="font-size:12px;"><?php echo date('d M Y, h:i A', strtotime($req->submission_date)); ?></td><td><a href="?view_id=<?php echo $req->id; ?>" class="btn btn-view">VIEW DETAILS</a></td></tr>
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
  <?php if($cnt_my_qa_pending > 0): ?>
  <div style="margin-top:16px;padding:20px 25px;background:#f3eeff;border:2px solid #6f42c1;display:flex;justify-content:space-between;align-items:center;border-radius:4px;">
    <div>
      <strong style="color:#4a148c;font-size:15px;">&#9876; <?php echo $cnt_my_qa_pending; ?> Request<?php echo $cnt_my_qa_pending>1?'s':''; ?> Awaiting YOUR QA Review</strong><br>
      <span style="font-size:13px;color:#6f42c1;">You have been nominated as QA / T&amp;E Engineer on <?php echo $cnt_my_qa_pending; ?> request<?php echo $cnt_my_qa_pending>1?'s':''; ?>.</span>
    </div>
    <a href="<?php echo esc_url(add_query_arg('mgr_action','qa_review')); ?>" class="btn" style="padding:10px 22px;font-size:13px;background:#6f42c1;color:#fff;">Review Now →</a>
  </div>
  <?php endif; ?>
</div>
<?php
    }

// =====================================================================
//  UHV STAFF VIEW (skip when action=qa_dashboard — that screen is rendered in USER VIEW block above)
// =====================================================================
} elseif ($user_role === 'UHV' && ($_GET['action'] ?? '') !== 'qa_dashboard') {
    $complete_id = intval($_GET['complete_id'] ?? 0);
    $uhv_msg    = sanitize_text_field($_GET['uhv_msg'] ?? '');
    $uhv_page_action = sanitize_text_field($_GET['action'] ?? '');

    // ── New TR form (same workflow as user submitters) ─────────────────
    if ($uhv_page_action === 'create_new') {
        $resume_id = intval($_GET['resume_draft'] ?? 0);
        if ($resume_id) {
            $existing_draft = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id=%d AND user_id=%d AND status IN ('draft_indenter','qa_rejected','rejected','recheck_indenter')",
                $resume_id, $user->ID
            ));
        } else {
            $existing_draft = null;
        }
        ?>
<div class="form-container">
<div class="role-indicator">UHV STAFF VIEW — NEW REQUEST | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>
<div style="margin-bottom:25px;"><a href="<?php echo esc_url(remove_query_arg(['action', 'resume_draft', 'uhv_msg'], get_permalink())); ?>" class="btn btn-primary">&larr; Back to Staff Dashboard</a></div>
<h1>New Test Requisition</h1>
<?php if ($uhv_msg === 'draft_saved'): ?>
<div class="draft-notice"><strong>&#128203; Draft saved.</strong> You can continue editing below.</div>
<?php endif; ?>
<?php
$_uhv_cn_errs = get_transient('uhv_errors_'.$user->ID);
if (!empty($_uhv_cn_errs)) {
    delete_transient('uhv_errors_'.$user->ID);
    echo "<div style='background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;padding:14px 20px;margin-bottom:20px;border-radius:4px;font-size:15px;'>";
    echo "<strong>Please fix the following errors:</strong><ul style='margin:8px 0 0 20px;padding:0;'>";
    foreach ($_uhv_cn_errs as $e) {
        echo '<li>'.esc_html($e).'</li>';
    }
    echo '</ul></div>';
}
uhv_request_form($emp, $existing_draft, admin_url('admin-ajax.php'));
?>
</div>
<?php

    // ── Individual staff form ─────────────────────────────────────────
    } elseif ($complete_id) {
        $fd = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id=%d AND status IN ('pending_staff','recheck_staff','pending_manager','approved','completed','recheck_indenter')", $complete_id
        ));
        if ($fd):
            $req_full  = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $fd->id));
            $_fd_steps = uhv_get_extended_pipeline_steps($fd);
            $is_done   = ($fd->status === 'completed');
            $bc        = $is_done ? 'badge-completed' : 'badge-approved';
            $_uhv_errs = get_transient('uhv_errors_'.$user->ID);
            if (!empty($_uhv_errs)) delete_transient('uhv_errors_'.$user->ID);
?>
<div class="container">
<div class="role-indicator">UHV STAFF VIEW | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>

<div style="margin-bottom:20px;display:flex;gap:10px;align-items:center;">
  <a href="<?php echo get_permalink(); ?>" class="btn btn-primary">&larr; Back to Dashboard</a>
  <?php uhv_print_button($fd->id); ?>
  <?php uhv_history_button($req_full ? $req_full->id : $fd->id); ?>
</div>

<?php if ($uhv_msg === 'uhv_draft_saved'): ?>
<div style="background:#d1ecf1;color:#0c5460;border:1px solid #bee5eb;padding:14px 20px;margin-bottom:20px;border-radius:4px;">&#10003; <strong>Draft saved successfully.</strong></div>
<?php elseif ($uhv_msg === 'validation_error' || !empty($_uhv_errs)): ?>
<div style="background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;padding:14px 20px;margin-bottom:25px;border-radius:4px;">
    <strong>&#9888; Please fix the following errors:</strong>
    <ul style="margin:10px 0 0 20px; padding:0;">
        <?php 
        if (!empty($_uhv_errs)) {
            foreach ($_uhv_errs as $err) echo '<li>'.esc_html($err).'</li>';
        } else {
            echo '<li>All fields in the Staff Review section are mandatory before forwarding to the manager.</li>';
        }
        ?>
    </ul>
</div>
<?php endif; ?>

<!-- ── Status card + pipeline ── -->
<div class="request-card">
  <div class="request-header">
    <div>
      <h2 style="margin:0;">TR No: <?php echo esc_html($fd->test_requisition_no); ?></h2>
      <small style="color:#666;">Approved: <?php echo !empty($fd->approval_date) ? date('d M Y, h:i A', strtotime($fd->approval_date)) : '—'; ?></small>
    </div>
    <span class="badge <?php echo $bc; ?>"><?php echo strtoupper($fd->status); ?></span>
  </div>

  <h3 style="margin-top:0;">Live Progress Pipeline</h3>
  <?php uhv_pipeline($_fd_steps); ?>

  <?php if (!empty($fd->draft_saved_at)): ?>
  <div class="draft-notice" style="margin-top:16px;"><strong>&#128203; Draft Saved:</strong> Last saved by <strong><?php echo esc_html($fd->draft_saved_by ?? 'Unknown'); ?></strong> on <strong><?php echo date('d M Y, h:i A', strtotime($fd->draft_saved_at)); ?></strong></div>
  <?php endif; ?>

  <?php if ($is_done): ?>
  <div style="background:#d4edda;border-left:4px solid #28a745;padding:12px 18px;margin:16px 0 0 0;border-radius:4px;font-size:17px;">&#10003; <strong>This form has been completed and is read-only.</strong></div>
  <?php else: ?>
  <div style="background:#e3f2fd;border-left:4px solid #2196F3;padding:12px 18px;margin:16px 0 0 0;border-radius:4px;font-size:17px;">&#128196; Fill in the UHV completion details for <strong><?php echo esc_html($fd->test_requisition_no); ?></strong> and submit when ready.</div>
  <?php endif; ?>
</div>

<?php if ($req_full): ?>

<!-- ── Indenter Request Details (read-only) ── -->
<div class="request-card" style="margin-top:20px;">
  <div style="background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:25px;margin-bottom:0;">
    <h3 style="margin-top:0;color:#343a40;font-size:18px;">&#128203; USER REQUEST DETAILS <span style="font-size:14px;font-weight:400;color:#6c757d;">(Read-Only)</span></h3>
    <table style="width:100%;border-collapse:collapse;font-size:17px;">
      <tr>
        <th style="border:1px solid #ccc;padding:10px;background:#e9ecef;width:22%;">Test Object</th>
        <td style="border:1px solid #ccc;padding:10px;"><?php echo esc_html($req_full->satellite_name); ?></td>
        <th style="border:1px solid #ccc;padding:10px;background:#e9ecef;width:22%;">Test Type</th>
        <td style="border:1px solid #ccc;padding:10px;"><?php echo esc_html(!empty($req_full->test_types) ? $req_full->test_types : $req_full->test_type); ?></td>
      </tr>
      <tr>
        <th style="border:1px solid #ccc;padding:10px;background:#e9ecef;">Test Required on</th>
        <td colspan="3" style="border:1px solid #ccc;padding:10px;"><?php echo !empty($req_full->test_required_on) ? date('d M Y', strtotime($req_full->test_required_on)) : '—'; ?></td>
      </tr>
      <tr>
        <th style="border:1px solid #ccc;padding:10px;background:#e9ecef;">Subsystem Engineer</th>
        <td style="border:1px solid #ccc;padding:10px;"><?php echo esc_html($req_full->sub_name.' ('.$req_full->sub_stno.')'); ?></td>
        <th style="border:1px solid #ccc;padding:10px;background:#e9ecef;">Section / Division</th>
        <td style="border:1px solid #ccc;padding:10px;"><?php echo esc_html(($req_full->sub_section ?: '—').' / '.($req_full->sub_division ?: '—')); ?></td>
      </tr>
      <tr>
        <th style="border:1px solid #ccc;padding:10px;background:#e9ecef;">Designation</th>
        <td style="border:1px solid #ccc;padding:10px;"><?php echo esc_html($req_full->sub_designation ?: '—'); ?></td>
        <th style="border:1px solid #ccc;padding:10px;background:#e9ecef;">Phone</th>
        <td style="border:1px solid #ccc;padding:10px;"><?php echo esc_html($req_full->sub_phone ?: '—'); ?></td>
      </tr>
      <?php if ($req_full->qa_exists === 'yes' && !empty($req_full->qa_name)): ?>
      <tr>
        <th style="border:1px solid #ccc;padding:10px;background:#e9ecef;">QA / T&amp;E Engineer</th>
        <td colspan="3" style="border:1px solid #ccc;padding:10px;"><?php echo esc_html($req_full->qa_name.' ('.$req_full->qa_stno.')'); ?><?php if(!empty($req_full->qa_designation)): ?> &mdash; <small style="color:#555;"><?php echo esc_html($req_full->qa_designation); ?></small><?php endif; ?><br><small style="color:#555;"><?php echo esc_html($req_full->qa_section); ?></small></td>
      </tr>
      <?php endif; ?>
      <?php if (!empty($req_full->special_requirements)): ?>
      <tr>
        <th style="border:1px solid #ccc;padding:10px;background:#e9ecef;vertical-align:top;">Special Requirements</th>
        <td colspan="3" style="border:1px solid #ccc;padding:10px;background:#fff3cd;"><?php echo nl2br(esc_html($req_full->special_requirements)); ?></td>
      </tr>
      <?php endif; ?>
    </table>
  <?php uhv_render_test_subforms_readonly($req_full); ?>

    <?php if (!empty($req_full->qa_reviewer_name)): ?>
    <h3 style="margin-top:22px;font-size:18px;color:#343a40;">QA / T&amp;E Engineer Review</h3>
    <table style="width:100%;border-collapse:collapse;font-size:17px;">
      <tr>
        <th style="border:1px solid #ccc;padding:9px;background:#e9ecef;width:22%;">Reviewed By</th>
        <td style="border:1px solid #ccc;padding:9px;"><?php echo esc_html($req_full->qa_reviewer_name ?: '—'); ?></td>
        <th style="border:1px solid #ccc;padding:9px;background:#e9ecef;width:22%;">Review Date</th>
        <td style="border:1px solid #ccc;padding:9px;"><?php echo !empty($req_full->qa_review_date) ? date('d M Y, h:i A', strtotime($req_full->qa_review_date)) : '—'; ?></td>
      </tr>
      <tr>
        <th style="border:1px solid #ccc;padding:9px;background:#e9ecef;">Decision</th>
        <td colspan="3" style="border:1px solid #ccc;padding:9px;">
          <?php echo $req_full->qa_decision === 'accept' ? '<span style="color:#28a745;font-weight:600;">&#10003; Accepted</span>' : '<span style="color:#fd7e14;font-weight:600;">&#10007; Rejected</span>'; ?>
          <?php if (!empty($req_full->qa_remarks)): ?> &mdash; <em style="font-size:13px;color:#555;"><?php echo esc_html($req_full->qa_remarks); ?></em><?php endif; ?>
        </td>
      </tr>
    </table>
    <?php endif; ?>

    <?php if (!empty($req_full->reviewed_by)): ?>
    <h3 style="margin-top:22px;font-size:18px;color:#343a40;">Manager Approval</h3>
    <table style="width:100%;border-collapse:collapse;font-size:17px;">
      <tr>
        <th style="border:1px solid #ccc;padding:9px;background:#e9ecef;width:22%;">Approved By</th>
        <td style="border:1px solid #ccc;padding:9px;"><?php echo esc_html($req_full->reviewed_by); ?></td>
        <th style="border:1px solid #ccc;padding:9px;background:#e9ecef;width:22%;">Approval Date</th>
        <td style="border:1px solid #ccc;padding:9px;"><?php echo !empty($req_full->approval_date) ? date('d M Y, h:i A', strtotime($req_full->approval_date)) : '—'; ?></td>
      </tr>
      <?php if (!empty($req_full->manager_comment)): ?>
      <tr>
        <th style="border:1px solid #ccc;padding:9px;background:#e9ecef;vertical-align:top;">Manager Comments</th>
        <td colspan="3" style="border:1px solid #ccc;padding:9px;"><?php echo nl2br(esc_html($req_full->manager_comment)); ?></td>
      </tr>
      <?php endif; ?>
    </table>
    
    <?php if ($req_full->status === 'approved' || $req_full->status === 'completed'): ?>
      <div style="margin-top:20px;">
        <?php uhv_render_per_test_risk_readonly($req_full, 'Section A — Risk Assessment (Filled by Staff)'); ?>
      </div>
    <?php endif; ?>
    <?php endif; // reviewed_by ?>
  </div>
</div>
<?php endif; // req_full ?>

<!-- ── UHV Fill Form (Phase 1 or 2) ── -->
<form method="post" enctype="multipart/form-data" data-form-status="<?php echo esc_attr($fd->status ?? ''); ?>" data-logged-in-name="<?php echo esc_attr($emp->name ?? ''); ?>">
<?php wp_nonce_field('uhv_action','uhv_nonce'); ?>
<input type="hidden" name="form_id" value="<?php echo $fd->id; ?>">

<?php 
    $ro_staff = ($fd->status === 'pending_manager' || $fd->status === 'recheck_indenter') ? 'readonly disabled' : '';
    if (in_array($fd->status, ['pending_staff','recheck_staff','pending_manager','recheck_indenter'])): 
?>
  <!-- ══ PHASE 1: STAFF REVIEW (Image 1) ══ -->
  <div class="request-card" style="margin-top:20px; border:2px solid #000; padding:30px;">
    <input type="hidden" name="uhv_staff_form_marker" value="1">
    
    <?php 
    $risk_map = uhv_get_per_test_risk($fd);
    $labels_ordered = uhv_get_selected_test_labels($fd);
    foreach ($labels_ordered as $i => $test_name):
        $risk = $risk_map[$test_name] ?? ['test_object_accepted'=>'','risk_assessed_uhv'=>'','rpn_uhv'=>'','risk_record_uhv'=>'','risk_table_url'=>''];
    ?>
    <div style="margin-bottom:40px; border-bottom:2px solid #eee; padding-bottom:25px;">
      <h3 style="margin:0 0 15px; font-size:18px; color:#0d6efd; font-weight:700;">Test requisitioned: <?php echo esc_html($test_name); ?></h3>
      <table style="width:100%; border-collapse:collapse; background:#fff; font-size:16px; border:1px solid #000;">
        <tr>
          <td style="border:1px solid #000; padding:15px; width:70%; font-weight:500; background:#f9f9f9;">Test request received, reviewed and accepted for testing</td>
          <td style="border:1px solid #000; padding:15px; width:30%; text-align:center;">
            <div style="display:flex; justify-content:center; gap:20px;">
              <label style="font-weight:600; cursor:pointer;"><input type="radio" name="risk_test_object_accepted[<?php echo $i; ?>]" value="yes" <?php checked(strtolower($risk['test_object_accepted'] ?? ''), 'yes'); ?> <?php echo $ro_staff; ?>> Yes</label>
              <label style="font-weight:600; cursor:pointer;"><input type="radio" name="risk_test_object_accepted[<?php echo $i; ?>]" value="no" <?php checked(strtolower($risk['test_object_accepted'] ?? ''), 'no'); ?> <?php echo $ro_staff; ?>> No</label>
            </div>
          </td>
        </tr>
        <tr>
          <td style="border:1px solid #000; padding:15px; width:33%; vertical-align:top;">
            <div style="margin-bottom:12px; font-weight:600; color:#333;">Risk Assessed as per Online QMS UHV Lab Risk Table:</div>
            <div style="display:flex; gap:20px;">
              <label style="font-weight:600; cursor:pointer;"><input type="radio" name="risk_assessed_uhv[<?php echo $i; ?>]" value="yes" <?php checked(strtolower($risk['risk_assessed_uhv'] ?? ''), 'yes'); ?> <?php echo $ro_staff; ?>> Yes</label>
              <label style="font-weight:600; cursor:pointer;"><input type="radio" name="risk_assessed_uhv[<?php echo $i; ?>]" value="no" <?php checked(strtolower($risk['risk_assessed_uhv'] ?? ''), 'no'); ?> <?php echo $ro_staff; ?>> No</label>
            </div>
          </td>
          <td style="border:1px solid #000; padding:15px; width:33%; vertical-align:top;">
            <div style="margin-bottom:12px; font-weight:600; color:#333;">Risk Priority No.(RPN):</div>
            <div style="display:flex; flex-direction:column; gap:8px;">
              <div style="display:flex; gap:20px; align-items:center;">
                <label style="font-weight:600; cursor:pointer;">&le; 4 <input type="radio" name="rpn_uhv[<?php echo $i; ?>]" value="lt4" <?php checked($risk['rpn_uhv'] ?? '', 'lt4'); ?> <?php echo $ro_staff; ?> onclick="uhvToggleRiskUpload(<?php echo $i; ?>, 'lt4')"></label>
                <label style="font-weight:600; cursor:pointer;">&ge; 5 <input type="radio" name="rpn_uhv[<?php echo $i; ?>]" value="gte5" <?php checked($risk['rpn_uhv'] ?? '', 'gte5'); ?> <?php echo $ro_staff; ?> onclick="uhvToggleRiskUpload(<?php echo $i; ?>, 'gte5')"></label>
              </div>
              <div id="risk_upload_div_<?php echo $i; ?>" style="display:<?php echo (($risk['rpn_uhv']??'')==='gte5')?'block':'none'; ?>; margin-top:10px; border:1px dashed #0d6efd; padding:8px; border-radius:4px; background:#f0f7ff;">
                <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px; color:#0d6efd;">Upload Risk Table (PDF/Image):</label>
                <input type="file" name="risk_table_file_<?php echo $i; ?>" accept=".pdf,image/*" style="font-size:12px; width:100%;">
                <?php if (!empty($risk['risk_table_url'])): ?>
                  <div style="margin-top:5px;"><small>Existing: <a href="<?php echo esc_url($risk['risk_table_url']); ?>" target="_blank">View File</a></small></div>
                  <input type="hidden" name="existing_risk_table_url[<?php echo $i; ?>]" value="<?php echo esc_attr($risk['risk_table_url']); ?>">
                <?php endif; ?>
              </div>
            </div>
            <small style="display:block; font-size:11px; color:#666; margin-top:8px;">(as per online QMS Risk Table to be filled if RPN &ge; 5)</small>
          </td>
          <td style="border:1px solid #000; padding:15px; width:34%; text-align:center; vertical-align:top;">
             <div style="margin-bottom:12px; font-weight:600; color:#333;">Risk Record:</div>
             <div style="display:flex; justify-content:center; gap:15px; font-weight:600;">
               <label style="cursor:pointer;"><input type="radio" name="risk_record_uhv[<?php echo $i; ?>]" value="yes" <?php checked(strtolower($risk['risk_record_uhv'] ?? ''), 'yes'); ?> <?php echo $ro_staff; ?>> Yes</label>
               <label style="cursor:pointer;"><input type="radio" name="risk_record_uhv[<?php echo $i; ?>]" value="no" <?php checked(strtolower($risk['risk_record_uhv'] ?? ''), 'no'); ?> <?php echo $ro_staff; ?>> No</label>
               <label style="cursor:pointer;"><input type="radio" name="risk_record_uhv[<?php echo $i; ?>]" value="na" <?php checked(strtolower($risk['risk_record_uhv'] ?? ''), 'na'); ?> <?php echo $ro_staff; ?>> NA</label>
             </div>
          </td>
        </tr>
      </table>
    </div>
    <?php endforeach; ?>

    <div style="margin-top:20px;">
      <label style="display:block; margin-bottom:10px; font-weight:700; color:#333; font-size:15px;">Review Remarks / Recheck Instructions <span style="font-weight:400; color:#666; font-size:13px;">(Mandatory for Recheck)</span>:</label>
      <textarea name="staff_review_comment" placeholder="Enter comments for the manager or instructions for user recheck..." style="width:100%; min-height:100px; border:1px solid #000; border-radius:4px; padding:15px; font-family:inherit; font-size:15px;"></textarea>
    </div>

    <?php if (!$ro_staff): // Only show review actions if form is editable (not pending_manager/recheck_indenter) ?>
    <div style="margin-top:40px; border-top:2px solid #eee; padding-top:25px; display:flex; gap:15px; justify-content:flex-end; align-items:center;">
      <button type="submit" name="save_draft" class="btn btn-draft" style="background:#6c757d; color:#fff; border:none; padding:12px 25px; font-weight:600;">&#128190; SAVE DRAFT</button>
      <button type="submit" name="staff_review_action" value="recheck_indenter" class="btn btn-reject" style="background:#fd7e14; border:none; padding:12px 25px; font-weight:600; color:#fff;" onclick="return confirm('Send this request back to the user for clarification?');">↩ Send to User for Recheck</button>
      <button type="submit" name="staff_review_action" value="forward_manager" class="btn btn-approve" style="background:#28a745; border:none; padding:12px 35px; font-weight:700; color:#fff; font-size:16px;">Forward to Manager &rarr;</button>
    </div>
    <?php endif; ?>
    <script>
    function uhvToggleRiskUpload(idx, val) {
        const div = document.getElementById('risk_upload_div_' + idx);
        if (div) {
            div.style.display = (val === 'gte5') ? 'block' : 'none';
        }
    }
    </script>
  </div>

<?php else: ?>
  <!-- ══ PHASE 2: STAFF EXECUTION (Image 2) ══ -->
  <div class="request-card" style="margin-top:20px; padding:0; border:none; box-shadow:none;">
    
    <!-- Section A Reference -->
    <!-- Section A Reference -->
    <?php uhv_render_per_test_risk_readonly($fd, 'Section A — Risk Assessment (Reference)'); ?>

    <?php if (stripos($fd->test_types, 'Bombing') !== false): 
        $bstaff = json_decode($fd->bombing_staff_json ?? '{}', true);
        $bcl = $bstaff['checklist'] ?? [];
        $blu = $bstaff['loading_unloading'] ?? [];
        $bmsld = $bstaff['msld'] ?? [];
    ?>
    <!-- BOMBING EXECUTION DETAILS (MATCHING IMAGE 1 & 2) -->
    <div style="border:2px solid #000; margin-bottom:30px; background:#fff; border-radius:6px; overflow:hidden;">
        <div style="background:#000; color:#fff; padding:12px 18px; font-weight:700; font-size:16px;">
            BOMBING & FINE LEAK DETECTION — EXECUTION DETAILS
        </div>
        
        <div style="padding:20px;">
            <h4 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:8px;">1. Check List (Ensure following and tick before test)</h4>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:25px;">
                <?php 
                $cl_items = [
                    1 => "Check the availability of Helium Gas",
                    2 => "Ensure ESD safety is provided.",
                    3 => "Confirm all the timers are connected to UPS Power",
                    4 => "Check timer is in RESET mode before starting the test.",
                    5 => "Check chamber lid is properly tighten",
                    6 => "Ensure chamber is evacuated sufficiently",
                    7 => "Ensure chamber is Pressurized to required Pressure",
                    8 => "Ensure timer is set to required dwell time",
                    9 => "Ensure MSLD is calibrated before use"
                ];
                foreach ($cl_items as $idx => $label): ?>
                <label style="display:flex; align-items:center; gap:10px; font-size:14px; cursor:pointer; background:#f9f9f9; padding:8px; border-radius:4px; border:1px solid #eee;">
                    <input type="checkbox" name="bombing_checklist[<?php echo $idx; ?>]" value="yes" <?php echo ($bcl[$idx]??'')==='yes'?'checked':''; ?>>
                    <span><?php echo $idx . '. ' . $label; ?></span>
                </label>
                <?php endforeach; ?>
            </div>

            <h4 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:8px;">2. Loading & Unloading details for Multi component Bombing station</h4>
            <div style="overflow-x:auto; margin-bottom:20px;">
                <table style="width:100%; border-collapse:collapse; background:#fff; font-size:13px;">
                    <thead>
                        <tr style="background:#f2f2f2;">
                            <th style="border:1px solid #000; padding:5px;">Req. Received on</th>
                            <th style="border:1px solid #000; padding:5px;">Component Name</th>
                            <th style="border:1px solid #000; padding:5px;">Qty</th>
                            <th style="border:1px solid #000; padding:5px;">Pressure</th>
                            <th style="border:1px solid #000; padding:5px;">Chamber utilized</th>
                            <th style="border:1px solid #000; padding:5px;">Duration</th>
                            <th style="border:1px solid #000; padding:5px;">Loaded ON</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for($i=0; $i<4; $i++): $r = $blu[$i] ?? []; ?>
                        <tr>
                            <td style="border:1px solid #000; padding:2px;"><input type="text" name="bombing_load_received_on[]" value="<?php echo esc_attr($r['received_on']??''); ?>" style="width:100%; border:none; padding:4px;"></td>
                            <td style="border:1px solid #000; padding:2px;"><input type="text" name="bombing_load_name[]" value="<?php echo esc_attr($r['name']??''); ?>" style="width:100%; border:none; padding:4px;"></td>
                            <td style="border:1px solid #000; padding:2px;"><input type="number" name="bombing_load_qty[]" value="<?php echo esc_attr($r['qty']??''); ?>" style="width:100%; border:1px solid #ccc; padding:6px; background:#fff; font-size:14px;"></td>
                            <td style="border:1px solid #000; padding:2px;"><input type="text" name="bombing_load_pressure[]" value="<?php echo esc_attr($r['pressure']??''); ?>" style="width:100%; border:none; padding:4px;"></td>
                            <td style="border:1px solid #000; padding:2px;"><input type="text" name="bombing_load_chamber[]" value="<?php echo esc_attr($r['chamber']??''); ?>" style="width:100%; border:none; padding:4px;"></td>
                            <td style="border:1px solid #000; padding:2px;"><input type="text" name="bombing_load_duration[]" value="<?php echo esc_attr($r['duration']??''); ?>" style="width:100%; border:none; padding:4px;"></td>
                            <td style="border:1px solid #000; padding:2px;"><input type="text" name="bombing_load_on[]" value="<?php echo esc_attr($r['on']??''); ?>" style="width:100%; border:none; padding:4px;"></td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
            
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:25px;">
                <div style="border:1px solid #eee; padding:10px; border-radius:4px;">
                    <label style="font-weight:600; font-size:13px;">Loaded by (Name & Sig):</label>
                    <input type="text" name="bombing_loaded_by" value="<?php echo esc_attr($bstaff['loaded_by']??''); ?>" style="width:100%; padding:8px; border:1px solid #ccc; margin-top:5px;">
                </div>
                <div style="border:1px solid #eee; padding:10px; border-radius:4px;">
                    <label style="font-weight:600; font-size:13px;">Unloaded by (Name & Sig):</label>
                    <input type="text" name="bombing_unloaded_by" value="<?php echo esc_attr($bstaff['unloaded_by']??''); ?>" style="width:100%; padding:8px; border:1px solid #ccc; margin-top:5px;">
                </div>
            </div>

            <h4 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:8px;">3. Fine Leak Detection using MSLD</h4>
            <div style="display:grid; grid-template-columns: 1fr 2fr; gap:20px; margin-bottom:15px;">
                <div style="background:#f9f9f9; padding:10px; border:1px solid #eee; border-radius:4px;">
                    <label style="font-weight:600; font-size:13px; display:block; margin-bottom:5px;">MSLD Calibrated:</label>
                    <label><input type="radio" name="bombing_msld_calibrated" value="yes" <?php echo ($bmsld['calibrated']??'')==='yes'?'checked':''; ?>> Yes</label>
                    <label style="margin-left:10px;"><input type="radio" name="bombing_msld_calibrated" value="no" <?php echo ($bmsld['calibrated']??'')==='no'?'checked':''; ?>> No</label>
                </div>
                <div style="background:#f9f9f9; padding:10px; border:1px solid #eee; border-radius:4px;">
                    <label style="font-weight:600; font-size:13px; display:block; margin-bottom:5px;">MSLD used:</label>
                    <?php foreach(['401.1','401.2','401.3','401.4'] as $m): ?>
                    <label style="margin-right:15px;"><input type="checkbox" name="bombing_msld_used[]" value="<?php echo $m; ?>" <?php echo in_array($m, $bmsld['used']??[])?'checked':''; ?>> <?php echo $m; ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:15px;">
                <div style="border:1px solid #eee; padding:10px; border-radius:4px;">
                    <label style="font-weight:600; font-size:13px;">Test Started ON:</label>
                    <input type="text" name="bombing_msld_started" value="<?php echo esc_attr($bmsld['started']??''); ?>" style="width:100%; padding:8px; border:1px solid #ccc; margin-top:5px;">
                </div>
                <div style="border:1px solid #eee; padding:10px; border-radius:4px;">
                    <label style="font-weight:600; font-size:13px;">Ended ON:</label>
                    <input type="text" name="bombing_msld_ended" value="<?php echo esc_attr($bmsld['ended']??''); ?>" style="width:100%; padding:8px; border:1px solid #ccc; margin-top:5px;">
                </div>
            </div>
            
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                <div style="border:1px solid #eee; padding:10px; border-radius:4px;">
                    <label style="font-weight:600; font-size:13px;">Staff Name:</label>
                    <input type="text" name="bombing_msld_staff" value="<?php echo esc_attr($bmsld['staff']??''); ?>" style="width:100%; padding:8px; border:1px solid #ccc; margin-top:5px;">
                </div>
                <div style="border:1px solid #eee; padding:10px; border-radius:4px;">
                    <label style="font-weight:600; font-size:13px;">Staff Signature:</label>
                    <input type="text" name="bombing_msld_sig" value="<?php echo esc_attr($bmsld['sig']??''); ?>" style="width:100%; padding:8px; border:1px solid #ccc; margin-top:5px;">
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Section B -->
    <div style="border:1px solid #000; margin-bottom:30px;">
      <h3 style="margin:0; padding:12px; background:#f8f9fa; border-bottom:1px solid #000; font-size:17px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">SECTION B — TEST EXECUTION DETAILS</h3>
      <table style="width:100%; border-collapse:collapse; font-size:16px;">
        <tr>
          <th style="border:1px solid #000; padding:15px; text-align:left; background:#fff; width:45%;">Test Started on <span style="color:#dc3545;">*</span></th>
          <td style="border:1px solid #000; padding:10px;"><input type="datetime-local" class="block" name="test_started_datetime" value="<?php echo esc_attr(str_replace(' ','T',$fd->test_started_datetime ?? '')); ?>" style="border:1px solid #ccc; padding:8px;"></td>
        </tr>
        <tr>
          <th style="border:1px solid #000; padding:15px; text-align:left; background:#fff;">Test Completed on <span style="color:#dc3545;">*</span></th>
          <td style="border:1px solid #000; padding:10px;"><input type="datetime-local" class="block" name="test_completed_datetime" value="<?php echo esc_attr(str_replace(' ','T',$fd->test_completed_datetime ?? '')); ?>" style="border:1px solid #ccc; padding:8px;"></td>
        </tr>
        <tr>
          <th style="border:1px solid #000; padding:15px; text-align:left; background:#fff;">Test Duration <small style="font-weight:400; color:#666;">(auto-calculated)</small></th>
          <td style="border:1px solid #000; padding:10px;"><input type="text" class="block" name="test_duration" value="<?php echo esc_attr($fd->test_duration ?? ''); ?>" placeholder="HH:MM:SS" readonly style="background:#f8f9fa; cursor:not-allowed; border:1px solid #ccc; padding:8px;"></td>
        </tr>
        <tr>
          <th style="border:1px solid #000; padding:15px; text-align:left; background:#fff;">Test Completed On-Time?</th>
          <td style="border:1px solid #000; padding:10px;">
            <input type="hidden" name="test_on_time" id="testontime_val" value="<?php echo esc_attr(ucfirst(strtolower($fd->test_on_time ?? ''))); ?>">
            <div style="display:flex; gap:20px; align-items:center;">
              <label style="cursor:pointer; display:flex; align-items:center; gap:8px;"><input type="checkbox" id="testontime_yes" <?php echo (strtolower($fd->test_on_time??'')=='yes')?'checked':''; ?> onchange="uhvToggleCb(this,'testontime_no','testontime_val','Yes')"> Yes</label>
              <label style="cursor:pointer; display:flex; align-items:center; gap:8px;"><input type="checkbox" id="testontime_no" <?php echo (strtolower($fd->test_on_time??'')=='no')?'checked':''; ?> onchange="uhvToggleCb(this,'testontime_yes','testontime_val','No')"> No</label>
            </div>
          </td>
        </tr>
        <tr>
          <th style="border:1px solid #000; padding:15px; text-align:left; background:#fff;">Test Code</th>
          <td style="border:1px solid #000; padding:10px;"><input type="text" class="block" name="test_code" value="<?php echo esc_attr($fd->test_code ?? ''); ?>" placeholder="e.g. URSC-TC-001" style="border:1px solid #ccc; padding:8px;"></td>
        </tr>
      </table>
    </div>

    <!-- Section C -->
    <div style="border:1px solid #000; margin-bottom:30px;">
      <h3 style="margin:0; padding:12px; background:#f8f9fa; border-bottom:1px solid #000; font-size:17px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">SECTION C — SPECIMEN COLLECTION &amp; CLOSURE</h3>
      <table style="width:100%; border-collapse:collapse; font-size:16px;">
        <tr style="background:#000; color:#fff;"><th colspan="2" style="padding:10px; text-align:left; font-weight:600;">Test Specimen Collected By</th></tr>
        <tr>
          <th style="border:1px solid #000; padding:15px; text-align:left; background:#fff; width:45%;">Name <span style="color:#dc3545;">*</span></th>
          <td style="border:1px solid #000; padding:10px;"><input type="text" class="block" name="specimen_collected_by_name" value="<?php echo esc_attr($fd->specimen_collected_by_name ?? ''); ?>" placeholder="Auto-filled with your name" data-auto-fill="logged-in-name" style="border:1px solid #ccc; padding:8px;"></td>
        </tr>
        <tr>
          <th style="border:1px solid #000; padding:15px; text-align:left; background:#fff;">Signature / Initials <span style="color:#dc3545;">*</span></th>
          <td style="border:1px solid #000; padding:10px;"><input type="text" class="block" name="specimen_collected_by_sig" value="<?php echo esc_attr($fd->specimen_collected_by_sig ?? ''); ?>" placeholder="Auto-filled with name &amp; timestamp" data-auto-fill="signature" style="border:1px solid #ccc; padding:8px;"></td>
        </tr>
        <tr style="background:#000; color:#fff;"><th colspan="2" style="padding:10px; text-align:left; font-weight:600;">Verification &amp; Requisition Closed By <small style="font-weight:400;">(Dy. Manager UHV or Competent Authority)</small></th></tr>
        <tr>
          <th style="border:1px solid #000; padding:15px; text-align:left; background:#fff; width:45%;">Name <span style="color:#dc3545;">*</span></th>
          <td style="border:1px solid #000; padding:10px;"><input type="text" class="block" name="verification_closed_by_name" value="<?php echo esc_attr($fd->verification_closed_by_name ?? ''); ?>" placeholder="Auto-filled with your name" data-auto-fill="logged-in-name" style="border:1px solid #ccc; padding:8px;"></td>
        </tr>
        <tr>
          <th style="border:1px solid #000; padding:15px; text-align:left; background:#fff;">Signature / Initials <span style="color:#dc3545;">*</span></th>
          <td style="border:1px solid #000; padding:10px;"><input type="text" class="block" name="verification_closed_by_sig" value="<?php echo esc_attr($fd->verification_closed_by_sig ?? ''); ?>" placeholder="Auto-filled with name &amp; timestamp" data-auto-fill="signature" style="border:1px solid #ccc; padding:8px;"></td>
        </tr>
      </table>
    </div>

    <?php if (!$is_done): ?>
    <div style="margin-top:25px; text-align:right; display:flex; justify-content:flex-end; gap:15px;">
      <button type="submit" name="save_draft" class="btn btn-draft" style="background:#6c757d; color:#fff; border:none; padding:12px 25px; font-weight:600;">&#128190; SAVE DRAFT</button>
      <button type="submit" name="complete_uhv" class="btn btn-submit" style="background:#198754; color:#fff; border:none; padding:12px 35px; font-weight:700; font-size:16px; box-shadow:0 4px 10px rgba(25,135,84,0.3);" onclick="return confirm('Complete and submit this form? This action cannot be undone.')">&#10003; COMPLETE &amp; SUBMIT</button>
    </div>
    <?php endif; ?>
  </div>
<?php endif; ?>
</form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var fs = document.querySelector('[data-form-status]') ? document.querySelector('[data-form-status]').getAttribute('data-form-status') : '';
    var ln = document.querySelector('[data-logged-in-name]') ? document.querySelector('[data-logged-in-name]').getAttribute('data-logged-in-name') : '';
    if (fs === 'completed') {
        var els = document.querySelectorAll('form input, form textarea, form select, form button');
        els.forEach(function(el) { el.setAttribute('disabled', true); el.style.cssText += 'background:#f5f5f5;cursor:not-allowed;color:#666;opacity:.6;'; });
    }
    var ts = document.querySelector('[name="test_started_datetime"]');
    var tc = document.querySelector('[name="test_completed_datetime"]');
    var td = document.querySelector('[name="test_duration"]');
    if (ts && tc && td) {
        function calcDur() {
            if (!ts.value || !tc.value) { td.value = ''; return; }
            var diff = new Date(tc.value) - new Date(ts.value);
            if (diff <= 0) { td.value = ''; return; }
            var h = Math.floor(diff/3600000), m = Math.floor(diff%3600000/60000), s = Math.floor(diff%60000/1000);
            td.value = ('0'+h).slice(-2)+':'+('0'+m).slice(-2)+':'+('0'+s).slice(-2);
        }
        [ts, tc].forEach(function(el) { el.addEventListener('change', calcDur); });
    }
    if (ln) {
        var frm = document.querySelector('form');
        if (frm) {
            frm.addEventListener('submit', function() {
                function af(nm) { var el=document.querySelector('[name="'+nm+'"]'); if(el&&!el.value.trim()) el.value=ln; }
                function afsig(nm) { var el=document.querySelector('[name="'+nm+'"]'); if(el&&!el.value.trim()){ var n=new Date(); el.value=ln+' - '+('0'+n.getDate()).slice(-2)+'/'+(('0'+(n.getMonth()+1)).slice(-2))+'/'+n.getFullYear()+' '+('0'+n.getHours()).slice(-2)+':'+('0'+n.getMinutes()).slice(-2); } }
                af('specimen_collected_by_name'); afsig('specimen_collected_by_sig');
                af('verification_closed_by_name'); afsig('verification_closed_by_sig');
            });
        }
    }
});
</script>

<?php
        else: echo "<p style='text-align:center;color:#dc3545;padding:50px;'>Form not found or not approved.</p>"; endif;

    } else {
        $all_staff = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE status IN ('pending_staff','recheck_staff','approved','completed','pending_manager','recheck_indenter','qa_rejected','rejected') ORDER BY submission_date DESC"
        );
        $review_list  = array_filter((array)$all_staff, fn($r) => in_array($r->status, ['pending_staff','recheck_staff']));
        $active_list  = array_filter((array)$all_staff, fn($r) => in_array($r->status, ['approved','completed','pending_manager']));
        $sent_back_list = array_filter((array)$all_staff, fn($r) => in_array($r->status, ['recheck_indenter', 'qa_rejected', 'rejected']));
        
        $cnt_review    = count($review_list);
        $cnt_active    = count(array_filter($active_list, fn($r) => $r->status === 'approved'));
        $cnt_completed = count(array_filter($active_list, fn($r) => $r->status === 'completed'));
?>
<div class="container">
<div class="role-indicator">UHV STAFF VIEW | <?php echo esc_html($emp->name.' ('.$emp->stno.')'); ?></div>

<?php if ($uhv_msg === 'uhv_draft_saved'): ?>
<div style="background:#d1ecf1;color:#0c5460;border:1px solid #bee5eb;padding:14px 20px;margin-bottom:20px;border-radius:4px;">&#10003; <strong>Draft saved successfully.</strong></div>
<?php elseif ($uhv_msg === 'uhv_completed'): ?>
<div style="background:#d4edda;color:#155724;border:1px solid #c3e6cb;padding:14px 20px;margin-bottom:20px;border-radius:4px;">&#10003; <strong>Form completed and submitted successfully.</strong></div>
<?php elseif ($uhv_msg === 'recheck_sent'): ?>
<div style="background:#fff3cd;color:#856404;border:1px solid #ffc107;padding:14px 20px;margin-bottom:20px;border-radius:4px;">&#8617; <strong>Request sent back to the User for review/editing.</strong> They have been notified.</div>
<?php elseif ($uhv_msg === 'staff_reviewed'): ?>
<div style="background:#d4edda;color:#155724;border:1px solid #c3e6cb;padding:14px 20px;margin-bottom:20px;border-radius:4px;">&#10003; <strong>Staff review completed.</strong> Risk assessment has been forwarded to the manager.</div>
<?php endif; ?>

<?php
$uhv_staff_qa_pending = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$table} WHERE qa_stno=%s AND (status='pending_qa' OR (status IN ('pending_manager','pending') AND qa_decision=''))",
    $emp->stno
));
?>
<div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-bottom:22px;">
  <a href="<?php echo esc_url(add_query_arg('action', 'create_new', get_permalink())); ?>" class="btn btn-success" style="padding:12px 22px;">+ NEW TEST REQUEST</a>
  <a href="<?php echo esc_url(add_query_arg('action', 'qa_dashboard', get_permalink())); ?>" class="btn" style="background:#6f42c1;color:#fff;padding:12px 22px;">
    &#10003; QA REVIEW <span style="background:<?php echo $uhv_staff_qa_pending > 0 ? '#dc3545' : '#6c757d'; ?>;color:#fff;border-radius:50%;padding:1px 7px;font-size:11px;font-weight:700;margin-left:6px;"><?php echo (int) $uhv_staff_qa_pending; ?></span>
  </a>
</div>

<div class="stat-grid" style="margin-bottom:25px;">
  <a href="<?php echo esc_url(add_query_arg('action', 'view_staff', get_permalink())); ?>" class="stat-card" style="border-color:#0d6efd;color:#0d6efd;text-decoration:none;"><div class="stat-num"><?php echo $cnt_review; ?></div><div class="stat-lbl">Awaiting Review</div></a>
  <a href="<?php echo esc_url(add_query_arg('action', 'view_staff', get_permalink())); ?>" class="stat-card sc-pending" style="text-decoration:none;"><div class="stat-num"><?php echo $cnt_active; ?></div><div class="stat-lbl">Awaiting Completion</div></a>
  <a href="<?php echo esc_url(add_query_arg('action', 'view_staff', get_permalink())); ?>" class="stat-card sc-approved" style="text-decoration:none;"><div class="stat-num"><?php echo $cnt_completed; ?></div><div class="stat-lbl">Completed</div></a>
</div>

<!-- ══ LIST 1: AWAITING REVIEW ══ -->
<h3 style="margin-top:0;color:#0d6efd;">Awaiting Review (Phase 1)</h3>
<?php if (empty($review_list)): ?>
  <div style="padding:20px;background:#f9f9f9;border:1px solid #ddd;color:#666;margin-bottom:30px;">No requests awaiting review.</div>
<?php else: ?>
  <table class="list-table" id="table-staff-review">
    <thead><tr><th>TR No.</th><th>Test Object</th><th>Submitted By</th><th>Submitted Date</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($review_list as $_sf): ?>
      <tr>
        <td><strong><?php echo esc_html($_sf->test_requisition_no); ?></strong></td>
        <td><?php echo esc_html($_sf->satellite_name); ?></td>
        <td><?php echo esc_html($_sf->sub_name ?: '—'); ?></td>
        <td><?php echo !empty($_sf->submission_date) ? date('d M Y, h:i A', strtotime($_sf->submission_date)) : '—'; ?></td>
        <td><span class="badge" style="background:<?php echo ($_sf->status==='recheck_staff')?'#fd7e14':'#0d6efd'; ?>;color:#fff;"><?php echo ($_sf->status==='recheck_staff')?'RECHECK':'AWAITING REVIEW'; ?></span></td>
        <td><a href="<?php echo esc_url(add_query_arg('complete_id', $_sf->id, get_permalink())); ?>" class="btn btn-view" style="background:#0d6efd;">Open Staff Review</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<!-- ══ LIST 2: ACTIVE / COMPLETED ══ -->
<h3 style="margin-top:40px;">Active Testing & Completed (Phase 2)</h3>
<?php if (empty($active_list)): ?>
  <div style="padding:20px;background:#f9f9f9;border:1px solid #ddd;color:#666;">No active or completed tests.</div>
<?php else: ?>
  <table class="list-table" id="table-staff-active">
    <thead><tr><th>TR No.</th><th>Test Object</th><th>Submitted By</th><th>Submitted Date</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($active_list as $_sf): 
        $bc = 'badge-pending'; $lbl = 'Awaiting Completion'; $btn = 'Fill Staff Form'; $bs = '';
        if ($_sf->status === 'completed') { $bc = 'badge-completed'; $lbl = 'COMPLETED'; $btn = 'View Details'; $bs = 'background:#555;'; }
        elseif ($_sf->status === 'pending_manager') { $bc = 'badge-pending-qa'; $lbl = 'Pending Mgr Approval'; $btn = 'View Status'; $bs = 'background:#6c757d;'; }
        elseif (!empty($_sf->draft_saved_at)) { $bc = 'badge-pending'; $lbl = 'Draft Saved'; $btn = 'Continue Draft'; }
    ?>
      <tr>
        <td><strong><?php echo esc_html($_sf->test_requisition_no); ?></strong></td>
        <td><?php echo esc_html($_sf->satellite_name); ?></td>
        <td><?php echo esc_html($_sf->sub_name ?: '—'); ?></td>
        <td><?php echo !empty($_sf->submission_date) ? date('d M Y, h:i A', strtotime($_sf->submission_date)) : '—'; ?></td>
        <td><span class="badge <?php echo $bc; ?>"><?php echo $lbl; ?></span></td>
        <td><a href="<?php echo esc_url(add_query_arg('complete_id', $_sf->id, get_permalink())); ?>" class="btn btn-view" style="<?php echo $bs; ?>"><?php echo $btn; ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<!-- ══ LIST 3: SENT BACK / REJECTED (with user) ══ -->
<h3 style="margin-top:40px;color:#fd7e14;font-weight:700;">SENT BACK / REJECTED (AWAITING USER ACTION)</h3>
<?php if (empty($sent_back_list)): ?>
  <div style="padding:20px;background:#f9f9f9;border:1px solid #ddd;color:#666;">No requests are currently with the user for correction or follow-up.</div>
<?php else: ?>
  <table class="list-table" id="table-staff-sentback">
    <thead><tr><th>TR No.</th><th>Test Object</th><th>Submitted By</th><th>Submitted Date</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($sent_back_list as $_sf):
        if ($_sf->status === 'recheck_indenter') {
            $sb_bc = 'badge-qa-rejected';
            $sb_lbl = 'SENT BACK';
            $sb_href = esc_url(add_query_arg('complete_id', $_sf->id, get_permalink()));
            $sb_target = '';
        } elseif ($_sf->status === 'qa_rejected') {
            $sb_bc = 'badge-qa-rejected';
            $sb_lbl = 'QA REJECTED';
            $sb_href = esc_url(get_template_directory_uri() . '/uhv-pdf-generator.php?request_id=' . (int) $_sf->id);
            $sb_target = ' target="_blank" rel="noopener"';
        } else {
            $sb_bc = 'badge-rejected';
            $sb_lbl = 'REJECTED';
            $sb_href = esc_url(get_template_directory_uri() . '/uhv-pdf-generator.php?request_id=' . (int) $_sf->id);
            $sb_target = ' target="_blank" rel="noopener"';
        }
    ?>
      <tr>
        <td><strong><?php echo esc_html($_sf->test_requisition_no); ?></strong></td>
        <td><?php echo esc_html($_sf->satellite_name); ?></td>
        <td><?php echo esc_html($_sf->sub_name ?: '—'); ?></td>
        <td><?php echo !empty($_sf->submission_date) ? date('d M Y, h:i A', strtotime($_sf->submission_date)) : '—'; ?></td>
        <td><span class="badge <?php echo esc_attr($sb_bc); ?>"><?php echo esc_html($sb_lbl); ?></span></td>
        <td><a href="<?php echo $sb_href; ?>" class="btn btn-view" style="background:#fd7e14;"<?php echo $sb_target; ?>>VIEW DETAILS</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
</div>
<?php
    } // end else (dashboard)
}
?>
</script>

<style>
/* DataTables Custom Styling - Synced with CATVAC */
.dataTables_wrapper {
    margin-top: 25px;
    padding: 20px;
    background: #fdfdfd;
    border: 1px solid #eee;
    border-radius: 8px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.03);
}
.dataTables_length, .dataTables_filter {
    margin-bottom: 20px !important;
    font-weight: 600;
}
.dataTables_length select, .dataTables_filter input {
    border: 1px solid #ccc !important;
    border-radius: 4px !important;
    padding: 8px 12px !important;
    background: #fff !important;
    box-shadow: inset 0 1px 2px rgba(0,0,0,0.05);
}
.dataTables_filter input {
    width: 250px !important;
    margin-left: 10px !important;
}
.dataTables_info {
    font-weight: 600;
    color: #444;
    padding-top: 15px !important;
}
.dataTables_paginate {
    padding-top: 15px !important;
}
.dataTables_paginate .paginate_button {
    border-radius: 20px !important;
    border: 1px solid #ddd !important;
    background: #fff !important;
    color: #333 !important;
    margin-left: 5px !important;
    padding: 6px 14px !important;
    font-weight: 600 !important;
    transition: all 0.2s !important;
}
.dataTables_paginate .paginate_button.current, .dataTables_paginate .paginate_button.current:hover {
    background: #fff !important;
    color: #000 !important;
    border-color: #000 !important;
    box-shadow: none !important;
}
.dataTables_paginate .paginate_button:hover {
    background: #f0f0f0 !important;
    color: #000 !important;
    border-color: #bbb !important;
}

/* Fix DataTable Sorting Arrows on Black Headers */
table.dataTable thead .sorting,
table.dataTable thead .sorting_asc,
table.dataTable thead .sorting_desc,
table.dataTable thead .sorting_asc_disabled,
table.dataTable thead .sorting_desc_disabled {
    background-image: none !important; /* Remove grey arrows */
    position: relative;
    padding-right: 30px !important;
}

table.dataTable thead .sorting::after,
table.dataTable thead .sorting_asc::after,
table.dataTable thead .sorting_desc::after {
    position: absolute;
    right: 12px;
    font-size: 14px;
    opacity: 0.5;
    transition: all 0.2s;
}

table.dataTable thead .sorting::after { content: "↕"; opacity: 0.3; }
table.dataTable thead .sorting_asc::after { content: "↑"; opacity: 1; color: #ffc107; } /* Gold for active sort */
table.dataTable thead .sorting_desc::after { content: "↓"; opacity: 1; color: #ffc107; }

.list-table th {
    cursor: pointer;
    transition: background 0.2s;
}
.list-table th:hover {
    background: #222 !important;
}

</style>

<script>
jQuery(document).ready(function($) {
    if ($.fn.DataTable) {
        $('.list-table').each(function() {
            if ($(this).attr('id')) {
                $(this).DataTable({
                    "pageLength": 10,
                    "lengthMenu": [10, 20, 50, 100],
                    "order": [], // Initial order from PHP
                    "dom": '<"dt-top-row"lf>rt<"dt-bottom-row"ip>',
                    "language": {
                        "search": "Filter requests:",
                        "lengthMenu": "Show _MENU_ entries",
                        "paginate": {
                            "next": "Next →",
                            "previous": "← Prev"
                        }
                    }
                });
            }
        });
    }
});

function uhvToggleCb(el, otherId, hiddenId, val) {
    var other  = document.getElementById(otherId);
    var hidden = document.getElementById(hiddenId);
    if (el.checked) {
        if (other)  other.checked  = false;
        if (hidden) hidden.value   = val;
    } else {
        if (hidden) hidden.value   = '';
    }
}
function uhvToggleMultiCb(el, otherIds, hiddenId, val) {
    var hidden = document.getElementById(hiddenId);
    if (el.checked) {
        otherIds.forEach(function(id) {
            var other = document.getElementById(id);
            if (other) other.checked = false;
        });
        if (hidden) hidden.value = val;
    } else {
        if (hidden) hidden.value = '';
    }
}
</script>
<?php uhv_history_modal_html(); ?>
<?php

function uhv_print_button($id) {
    if (!$id) return;
    ?>
    <a href="<?php echo get_template_directory_uri(); ?>/uhv-pdf-generator.php?request_id=<?php echo $id; ?>" target="_blank" class="btn btn-primary" style="background:#000; color:#fff; display:inline-flex; align-items:center; gap:8px;">
        <span style="font-size:16px;">&#128424;</span> Print Document (PDF)
    </a>
    <?php
}
?>
 <?php get_footer(); ?>