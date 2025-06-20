<?php
session_start();
require 'config.php'; // Ensure this file properly connects to your database using PDO

// Check that the user is logged in.
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// Get the projectID from GET parameters.
$projectID = isset($_GET['projectID']) ? intval($_GET['projectID']) : 0;
if ($projectID <= 0) {
    die("Invalid Project ID");
}

// Define the ordered list of stages. This should be consistent across index.php and edit_project.php
$stagesOrder = [
    'Purchase Request',
    'RFQ 1',
    'RFQ 2',
    'RFQ 3',
    'Abstract of Quotation',
    'Purchase Order',
    'Notice of Award',
    'Notice to Proceed'
];

// Fetch project details along with creator data.
$stmt = $pdo->prepare("SELECT p.*, u.firstname, u.lastname, o.officename
                       FROM tblproject p
                       LEFT JOIN tbluser u ON p.userID = u.userID
                       LEFT JOIN officeid o ON u.officeID = o.officeID
                       WHERE p.projectID = ?");
$stmt->execute([$projectID]);
$project = $stmt->fetch();
if (!$project) {
    die("Project not found");
}

// Retrieve stages for the project.
$stmt2 = $pdo->prepare("SELECT * FROM tblproject_stages
                        WHERE projectID = ?
                        ORDER BY FIELD(stageName, 'Purchase Request','RFQ 1','RFQ 2','RFQ 3','Abstract of Quotation','Purchase Order','Notice of Award','Notice to Proceed')");
$stmt2->execute([$projectID]);
$stages = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// If no stages exist, create records for every stage.
// This handles cases where a project was added before this stage creation logic was in place.
if (empty($stages)) {
    foreach ($stagesOrder as $stageName) {
        $insertCreatedAt = null;
        // Only set createdAt for 'Purchase Request' if it's the first stage and no stages existed.
        // This makes sure older projects get their PR createdAt set if they weren't.
        if ($stageName === 'Purchase Request') {
            $insertCreatedAt = date("Y-m-d H:i:s"); // Set current timestamp for initial 'Purchase Request'
        }
        $stmtInsert = $pdo->prepare("INSERT INTO tblproject_stages (projectID, stageName, office, createdAt) VALUES (?, ?, ?, ?)");
        $stmtInsert->execute([$projectID, $stageName, "", $insertCreatedAt]);
    }
    // Re-fetch stages after creation
    $stmt2->execute([$projectID]);
    $stages = $stmt2->fetchAll(PDO::FETCH_ASSOC);
}

// Map stages by stageName for easy access.
$stagesMap = [];
foreach ($stages as $s) {
    $stagesMap[$s['stageName']] = $s;
}

// Process Project Header update (available only for admins).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_project_header'])) {
    if ($_SESSION['admin'] == 1) {
        $prNumber = trim($_POST['prNumber']);
        $projectDetails = trim($_POST['projectDetails']);
        if (empty($prNumber) || empty($projectDetails)) {
            $errorHeader = "PR Number and Project Details are required.";
        } else {
            $stmtUpdate = $pdo->prepare("UPDATE tblproject
                                          SET prNumber = ?, projectDetails = ?, editedAt = CURRENT_TIMESTAMP, editedBy = ?,
                                              lastAccessedAt = CURRENT_TIMESTAMP, lastAccessedBy = ?
                                          WHERE projectID = ?");
            $stmtUpdate->execute([$prNumber, $projectDetails, $_SESSION['userID'], $_SESSION['userID'], $projectID]);
            $successHeader = "Project updated successfully.";
            // Reload the updated project details.
            $stmt->execute([$projectID]);
            $project = $stmt->fetch();
        }
    }
}

// Process individual stage submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_stage'])) {
    $stageName = $_POST['stageName'];
    $safeStage = str_replace(' ', '_', $stageName);

    // Retrieve new inputs from datetime-local fields.
    $formCreated = isset($_POST["created_$safeStage"]) && !empty($_POST["created_$safeStage"]) ? $_POST["created_$safeStage"] : null;
    $approvedAt = isset($_POST['approvedAt']) && !empty($_POST['approvedAt']) ? $_POST['approvedAt'] : null;
    $office = isset($_POST["office_$safeStage"]) ? trim($_POST["office_$safeStage"]) : "";
    $remark = isset($_POST['remark']) ? trim($_POST['remark']) : "";

    // Determine if this is a "Submit" or "Unsubmit" action
    $isSubmittedVal = 1; // Default to submit
    $currentIsSubmittedInDB = (isset($stagesMap[$stageName]) && $stagesMap[$stageName]['isSubmitted'] == 1);

    if ($_SESSION['admin'] == 1 && $currentIsSubmittedInDB) {
        $isSubmittedVal = 0; // Admin clicked "Unsubmit"
    }

    // --- MODIFIED VALIDATION LOGIC HERE ---
    $validationFailed = false;
    if ($isSubmittedVal == 1) { // Only validate on "Submit" action
        if (empty($approvedAt) || empty($office) || empty($remark)) {
            $validationFailed = true;
        }
        // Special condition for 'Purchase Request': createdAt is auto-populated, so don't check $formCreated
        if ($stageName !== 'Purchase Request' && empty($formCreated)) {
            $validationFailed = true;
        }
    }

    if ($validationFailed) {
        $stageError = "All fields (Created, Approved, Office, and Remark) are required for stage '$stageName' to be submitted.";
    } else {
        // Prepare createdAt for update:
        // If it's the first time submitting this stage AND createdAt is empty, set it to now.
        // Otherwise, use the value from the form or its existing value (if un-submitting).
        $actualCreatedAt = $formCreated; // Start with the value from the form
        if ($isSubmittedVal == 1 && empty($actualCreatedAt)) {
            // This condition should primarily hit for stages other than "Purchase Request"
            // where createdAt was previously NULL, and the user is now submitting it.
            $actualCreatedAt = date("Y-m-d H:i:s");
        } elseif ($isSubmittedVal == 0) { // If un-submitting, retain current createdAt from DB
            $actualCreatedAt = $stagesMap[$stageName]['createdAt'] ?? null;
        }


        // Convert datetime-local values ("Y-m-d\TH:i") to MySQL datetime ("Y-m-d H:i:s").
        $created_dt = $actualCreatedAt ? date("Y-m-d H:i:s", strtotime($actualCreatedAt)) : null;
        $approved_dt = $approvedAt ? date("Y-m-d H:i:s", strtotime($approvedAt)) : null;

        $stmtStageUpdate = $pdo->prepare("UPDATE tblproject_stages
                                           SET createdAt = ?, approvedAt = ?, office = ?, remarks = ?, isSubmitted = ?
                                           WHERE projectID = ? AND stageName = ?");
        $stmtStageUpdate->execute([$created_dt, $approved_dt, $office, $remark, $isSubmittedVal, $projectID, $stageName]);
        $stageSuccess = "Stage '$stageName' updated successfully.";

        // If this is a "Submit" action (isSubmittedVal == 1), auto-update the next stage's createdAt if empty.
        if ($isSubmittedVal == 1) {
            $index = array_search($stageName, $stagesOrder);
            if ($index !== false && $index < count($stagesOrder) - 1) {
                $nextStageName = $stagesOrder[$index + 1];
                // Only update if the next stage's createdAt is currently empty or null
                if (!(isset($stagesMap[$nextStageName]) && !empty($stagesMap[$nextStageName]['createdAt']))) {
                    $now = date("Y-m-d H:i:s");
                    $stmtNext = $pdo->prepare("UPDATE tblproject_stages SET createdAt = ? WHERE projectID = ? AND stageName = ?");
                    $stmtNext->execute([$now, $projectID, $nextStageName]);
                }
            }
        }

        // Refresh stage records.
        $stmt2->execute([$projectID]);
        $stages = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        foreach ($stages as $s) {
            $stagesMap[$s['stageName']] = $s;
        }
        // Update project's last accessed fields.
        $pdo->prepare("UPDATE tblproject SET lastAccessedAt = CURRENT_TIMESTAMP, lastAccessedBy = ? WHERE projectID = ?")
            ->execute([$_SESSION['userID'], $projectID]);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Project</title>
    <link rel="stylesheet" href="assets/css/home.css">
    <style>
        /* Project Header Styling */
        .project-header {
            margin-bottom: 20px;
            border: 1px solid #ccc;
            padding: 10px;
            border-radius: 8px;
            background: #f9f9f9;
        }
        .project-header label {
            display: block;
            margin-top: 10px;
            font-weight: bold;
        }
        .project-header input, .project-header textarea {
            width: 100%;
            padding: 8px;
            margin-top: 5px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        /* Read-only field styling */
        .readonly-field {
            padding: 8px;
            margin-top: 5px;
            border: 1px solid #eee;
            background: #f1f1f1;
            border-radius: 4px; /* Added for consistency */
        }
        /* Back Button */
        .back-btn {
            display: inline-block;
            background-color: #0d47a1;
            color: white;
            padding: 10px 20px;
            margin-bottom: 20px;
            text-decoration: none;
            border-radius: 4px;
            font-weight: bold;
        }
        /* Stages Table Styling */
        table#stagesTable {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            table-layout: fixed; /* Ensures columns respect defined widths */
        }
        table#stagesTable th, table#stagesTable td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: center;
            vertical-align: middle; /* Align content vertically */
            word-wrap: break-word; /* Allow long words to break */
        }
        table#stagesTable th {
            background-color: #c62828;
            color: white;
        }
        table#stagesTable td input[type="datetime-local"],
        table#stagesTable td input[type="text"] {
            width: calc(100% - 10px); /* Adjust for padding/border */
            padding: 4px;
            box-sizing: border-box;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        table#stagesTable td input[disabled] {
            background-color: #e9e9e9;
            cursor: not-allowed;
        }
        /* Form for each stage row */
        form.stage-form {
            display: contents; /* Allows row elements to behave as direct table children */
        }
        /* Submit/Unsubmit button styling */
        .submit-stage-btn {
            padding: 8px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            background-color: #28a745; /* Green for submit */
            color: white;
        }
        .submit-stage-btn[disabled] {
            background-color: #cccccc;
            cursor: not-allowed;
        }
        .submit-stage-btn.unsubmit-btn {
            background-color: #dc3545; /* Red for unsubmit */
        }
    </style>
</head>
<body>
    <div class="header">
        <a href="index.php" style="display: flex; align-items: center; text-decoration: none; color: inherit;">
            <img src="assets/images/DEPED-LAOAG_SEAL_Glow.png" alt="DepEd Logo" class="header-logo">
            <div class="header-text">
                <div class="title-left">
                    SCHOOLS DIVISION OF LAOAG CITY<br>DEPARTMENT OF EDUCATION
                </div>
                <?php if (isset($showTitleRight) && $showTitleRight): ?>
                <div class="title-right">
                    Bids and Awards Committee Tracking System
                </div>
                <?php endif; ?>
            </div>
        </a>
        <div class="user-menu">
            <span class="user-name"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
            <div class="dropdown">
                <img src="https://cdn-icons-png.flaticon.com/512/149/149071.png" alt="User Icon" class="user-icon">
                <div class="dropdown-content">
                    <a href="logout.php" id="logoutBtn">Log out</a>
                </div>
            </div>
        </div>
    </div>

    <div class="dashboard-container">
        <a href="index.php" class="back-btn">&larr; Back to Dashboard</a>

        <h2>Edit Project</h2>

        <?php
            if (isset($errorHeader)) { echo "<p style='color:red;'>$errorHeader</p>"; }
            if (isset($successHeader)) { echo "<p style='color:green;'>$successHeader</p>"; }
            if (isset($stageError)) { echo "<p style='color:red;'>$stageError</p>"; }
        ?>

        <div class="project-header">
            <label for="prNumber">PR Number:</label>
            <?php if ($_SESSION['admin'] == 1): ?>
            <form action="edit_project.php?projectID=<?php echo $projectID; ?>" method="post" style="margin-bottom:10px;">
                <input type="text" name="prNumber" id="prNumber" value="<?php echo htmlspecialchars($project['prNumber']); ?>" required>
            <?php else: ?>
                <div class="readonly-field"><?php echo htmlspecialchars($project['prNumber']); ?></div>
            <?php endif; ?>

            <label for="projectDetails">Project Details:</label>
            <?php if ($_SESSION['admin'] == 1): ?>
                <textarea name="projectDetails" id="projectDetails" required><?php echo htmlspecialchars($project['projectDetails']); ?></textarea>
            <?php else: ?>
                <div class="readonly-field"><?php echo htmlspecialchars($project['projectDetails']); ?></div>
            <?php endif; ?>

            <label>User Info:</label>
            <p><?php echo htmlspecialchars($project['firstname'] . " " . $project['lastname'] . " | Office: " . ($project['officename'] ?? 'N/A')); ?></p>

            <label>Date Created:</label>
            <p><?php echo date("m-d-Y h:i A", strtotime($project['createdAt'])); ?></p>

            <label>Date Last Edited:</label>
            <?php
            $lastEdited = "Not Available";
            if ($project['lastAccessedAt'] && $project['lastAccessedBy']) {
                $stmtUser = $pdo->prepare("SELECT firstname, lastname FROM tbluser WHERE userID = ?");
                $stmtUser->execute([$project['lastAccessedBy']]);
                $lastUser = $stmtUser->fetch();
                if ($lastUser) {
                    $lastEdited = htmlspecialchars($lastUser['firstname'] . " " . $lastUser['lastname']) . ", accessed on " . date("m-d-Y h:i A", strtotime($project['lastAccessedAt']));
                }
            }
            ?>
            <p><?php echo htmlspecialchars($lastEdited); ?></p>
            <?php if ($_SESSION['admin'] == 1): ?>
                <button type="submit" name="update_project_header">Update Project Details</button>
            </form>
            <?php endif; ?>
        </div>

        <h3>Project Stages</h3>
        <?php if (isset($stageSuccess)) { echo "<p style='color:green;'>$stageSuccess</p>"; } ?>
        <table id="stagesTable">
            <thead>
                <tr>
                    <th style="width: 15%;">Stage</th>
                    <th style="width: 20%;">Created</th>
                    <th style="width: 20%;">Approved</th>
                    <th style="width: 15%;">Office</th>
                    <th style="width: 15%;">Remark</th>
                    <th style="width: 15%;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Loop through each stage.
                foreach ($stagesOrder as $index => $stage):
                    $safeStage = str_replace(' ', '_', $stage);
                    $currentStageData = $stagesMap[$stage] ?? null;

                    // Determine if this stage was submitted.
                    $currentSubmitted = ($currentStageData && $currentStageData['isSubmitted'] == 1);

                    // For datetime-local, format as "Y-m-d\TH:i"
                    $value_created = ($currentStageData && !empty($currentStageData['createdAt']))
                                     ? date("Y-m-d\TH:i", strtotime($currentStageData['createdAt'])) : "";
                    $value_approved = ($currentStageData && !empty($currentStageData['approvedAt']))
                                      ? date("Y-m-d\TH:i", strtotime($currentStageData['approvedAt'])) : "";
                    $value_office = ($currentStageData && !empty($currentStageData['office']))
                                    ? htmlspecialchars($currentStageData['office']) : "";
                    $value_remark = ($currentStageData && !empty($currentStageData['remarks']))
                                    ? htmlspecialchars($currentStageData['remarks']) : "";

                    // Determine whether submission is allowed for the CURRENT USER.
                    // A stage can be edited/submitted if:
                    // 1. It's the first stage OR
                    // 2. The previous stage is submitted.
                    $allowSubmissionToUser = false;
                    if ($index == 0) { // Purchase Request
                        $allowSubmissionToUser = true;
                    } else {
                        $prevStage = $stagesOrder[$index - 1];
                        if (isset($stagesMap[$prevStage]) && $stagesMap[$prevStage]['isSubmitted'] == 1) {
                            $allowSubmissionToUser = true;
                        }
                    }

                    // Fields are disabled if:
                    // - The stage is not allowed for submission OR
                    // - The stage is currently submitted AND the user is not an admin.
                    $disableFields = (!$allowSubmissionToUser) || ($currentSubmitted && $_SESSION['admin'] != 1);

                    // For 'Created' field:
                    // It should be disabled if:
                    // 1. The overall fields are disabled (due to being a non-submittable stage or submitted by non-admin)
                    // 2. It's the "Purchase Request" stage AND its value is already set (because it's auto-filled).
                    $disableCreatedField = $disableFields || ($stage === 'Purchase Request' && !empty($value_created));

                ?>
                <form action="edit_project.php?projectID=<?php echo $projectID; ?>" method="post" class="stage-form">
                    <tr data-stage="<?php echo htmlspecialchars($stage); ?>">
                        <td><?php echo htmlspecialchars($stage); ?></td>
                        <td>
                            <input type="datetime-local" name="created_<?php echo $safeStage; ?>"
                                   value="<?php echo $value_created; ?>"
                                   <?php if ($disableCreatedField) echo "disabled"; ?>>
                        </td>
                        <td>
                            <input type="datetime-local" name="approvedAt"
                                   value="<?php echo $value_approved; ?>"
                                   <?php if ($disableFields) echo "disabled"; ?>>
                        </td>
                        <td>
                            <input type="text" name="office_<?php echo $safeStage; ?>"
                                   value="<?php echo $value_office; ?>"
                                   <?php if ($disableFields) echo "disabled"; ?>>
                        </td>
                        <td>
                            <input type="text" name="remark"
                                   value="<?php echo $value_remark; ?>"
                                   <?php if ($disableFields) echo "disabled"; ?>>
                        </td>
                        <td>
                            <input type="hidden" name="stageName" value="<?php echo htmlspecialchars($stage); ?>">
                            <?php
                                if ($allowSubmissionToUser) {
                                    if ($currentSubmitted) {
                                        // Stage is finished.
                                        if ($_SESSION['admin'] == 1) {
                                            echo '<button type="submit" name="submit_stage" class="submit-stage-btn unsubmit-btn">Unsubmit</button>';
                                        } else {
                                            echo '<button type="button" class="submit-stage-btn" disabled>Finished</button>';
                                        }
                                    } else {
                                        echo '<button type="submit" name="submit_stage" class="submit-stage-btn">Submit</button>';
                                    }
                                } else {
                                    // If not allowed to submit, show disabled button or nothing
                                    echo '<button type="button" class="submit-stage-btn" disabled>Pending</button>';
                                }
                            ?>
                        </td>
                    </tr>
                </form>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>