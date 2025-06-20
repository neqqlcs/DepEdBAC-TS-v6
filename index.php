<?php
session_start();
require 'config.php'; // Ensure this file properly connects to your database using PDO

// Redirect if user is not logged in.
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// Define the ordered list of stages and their short forms for status display.
// This should be consistent across index.php and edit_project.php
$stagesOrder = [
    'Purchase Request' => 'PR',
    'RFQ 1' => 'RFQ1',
    'RFQ 2' => 'RFQ2',
    'RFQ 3' => 'RFQ3',
    'Abstract of Quotation' => 'AoQ',
    'Purchase Order' => 'PO',
    'Notice of Award' => 'NoA',
    'Notice to Proceed' => 'NtP'
];

/* ---------------------------
    Project Deletion (Admin Only)
------------------------------ */
if (isset($_GET['deleteProject'])) {
    if ($_SESSION['admin'] == 1) { // Only admin can delete.
        $delID = intval($_GET['deleteProject']);
        try {
            // Start a transaction for atomicity
            $pdo->beginTransaction();

            // Delete associated stages first
            $stmtDelStages = $pdo->prepare("DELETE FROM tblproject_stages WHERE projectID = ?");
            $stmtDelStages->execute([$delID]);

            // Then delete the project itself
            $stmtDel = $pdo->prepare("DELETE FROM tblproject WHERE projectID = ?");
            $stmtDel->execute([$delID]);

            $pdo->commit(); // Commit the transaction
            header("Location: index.php");
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack(); // Rollback on error
            $deleteProjectError = "Error deleting project: " . $e->getMessage();
        }
    }
}

/* ---------------------------
    Add Project Processing
------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['addProject'])) {
    // Retrieve inputs from the Add Project form.
    $prNumber = trim($_POST['prNumber']);
    $projectDetails = trim($_POST['projectDetails']);
    // Removed $remarks as per new requirement for the "Add Project" form.
    $userID = $_SESSION['userID']; // The creator's user ID.

    if (empty($prNumber) || empty($projectDetails)) {
        $projectError = "Please fill in all required fields.";
    } else {
        try {
            // Start a transaction for atomicity
            $pdo->beginTransaction();

            // Insert into tblproject (remarks column will now be NULL or default empty if not provided)
            $stmt = $pdo->prepare("INSERT INTO tblproject (prNumber, projectDetails, userID) VALUES (?, ?, ?)");
            $stmt->execute([$prNumber, $projectDetails, $userID]);
            $newProjectID = $pdo->lastInsertId(); // Get the ID of the newly inserted project

            // Insert all stages for the new project,
            // automatically setting createdAt for 'Purchase Request'
            foreach ($stagesOrder as $stageName => $shortForm) { // Use $stagesOrder defined above
                $insertCreatedAt = null;
                if ($stageName === 'Purchase Request') {
                    // Set current timestamp for 'Purchase Request' on project creation
                    $insertCreatedAt = date("Y-m-d H:i:s");
                }
                $stmtInsertStage = $pdo->prepare("INSERT INTO tblproject_stages (projectID, stageName, createdAt) VALUES (?, ?, ?)");
                $stmtInsertStage->execute([$newProjectID, $stageName, $insertCreatedAt]);
            }

            $pdo->commit(); // Commit the transaction
            header("Location: index.php");
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack(); // Rollback on error
            $projectError = "Error adding project: " . $e->getMessage();
        }
    }
}

/* ---------------------------
    Retrieve Projects (with optional search and current stage status)
------------------------------ */
$search = "";
if (isset($_GET['search'])) {
    $search = trim($_GET['search']);
}

// Prepare the base query for projects.
// We'll join with tbluser for creator name.
// For status, we need to find the latest stage that is NOT submitted, or the last submitted stage if all are submitted.
$sql = "SELECT p.*, u.firstname, u.lastname,
        (SELECT s.stageName
         FROM tblproject_stages s
         WHERE s.projectID = p.projectID
         ORDER BY FIELD(s.stageName, 'Purchase Request','RFQ 1','RFQ 2','RFQ 3','Abstract of Quotation','Purchase Order','Notice of Award','Notice to Proceed') ASC
         LIMIT 1 OFFSET 0 -- This gets the first stage which is 'Purchase Request' initially
        ) AS first_stage_name,
        (SELECT s_current.stageName
         FROM tblproject_stages s_current
         WHERE s_current.projectID = p.projectID
         AND s_current.isSubmitted = 0 -- Get the first UN-submitted stage
         ORDER BY FIELD(s_current.stageName, 'Purchase Request','RFQ 1','RFQ 2','RFQ 3','Abstract of Quotation','Purchase Order','Notice of Award','Notice to Proceed') ASC
         LIMIT 1
        ) AS current_unsubmitted_stage,
        (SELECT s_last_submitted.stageName
         FROM tblproject_stages s_last_submitted
         WHERE s_last_submitted.projectID = p.projectID
         AND s_last_submitted.isSubmitted = 1 -- Get the LAST submitted stage if all are submitted
         ORDER BY FIELD(s_last_submitted.stageName, 'Purchase Request','RFQ 1','RFQ 2','RFQ 3','Abstract of Quotation','Purchase Order','Notice of Award','Notice to Proceed') DESC
         LIMIT 1
        ) AS last_submitted_stage_if_all_submitted
        FROM tblproject p
        JOIN tbluser u ON p.userID = u.userID";

// Add search conditions if a search query is present.
if ($search !== "") {
    $sql .= " WHERE p.projectDetails LIKE ? OR p.prNumber LIKE ?";
}
$sql .= " ORDER BY p.createdAt DESC"; // Always order by creation date

$stmt = $pdo->prepare($sql);

if ($search !== "") {
    $stmt->execute(["%$search%", "%$search%"]);
} else {
    $stmt->execute();
}
$projects = $stmt->fetchAll();

// Function to get the display status from the stage name
function getDisplayStatus($stageName, $stagesOrder) {
    return $stagesOrder[$stageName] ?? 'N/A';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard - DepEd BAC Tracking System</title>
    <link rel="stylesheet" href="assets/css/home.css">
    <style>
        /* Modal styling for Add Project Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
        }
        .modal-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 90%;
            max-width: 500px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close:hover { color: black; }
        form label {
            display: block;
            margin-top: 10px;
        }
        form input, form textarea {
            width: 100%;
            padding: 8px;
            margin-top: 4px;
            box-sizing: border-box;
        }
        form button {
            margin-top: 15px;
            padding: 10px;
            width: 100%;
            border: none;
            background-color: #0d47a1;
            color: white;
            font-weight: bold;
            border-radius: 4px;
            cursor: pointer;
        }
        /* Table header styling updated to show all columns */
        .table-header-custom, .table-row-custom {
            display: flex;
            background-color: #c62828;
            color: white;
            padding: 12px 20px;
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
            font-weight: bold;
            margin-top: 20px;
            align-items: center;
        }
        .table-row-custom {
            background-color: #fefefe;
            color: #333;
            border-bottom: 1px solid #eee;
        }
        .header-item, .row-item {
            padding: 0 5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        /* Adjusted widths - REMEMBER TO ADJUST THESE IF YOU ADD/REMOVE COLUMNS */
        .header-item:nth-child(1), .row-item:nth-child(1) { /* PR Number */
            flex: 0 0 100px;
            text-align: center;
        }
        .header-item:nth-child(2), .row-item:nth-child(2) { /* Project Details */
            flex: 1;
        }
        .header-item:nth-child(3), .row-item:nth-child(3) { /* Created By */
            flex: 0 0 100px; /* Adjusted width for "J. Doe" format */
            text-align: center;
        }
        .header-item:nth-child(4), .row-item:nth-child(4) { /* Date Created */
            flex: 0 0 120px;
            text-align: center;
        }
        .header-item:nth-child(5), .row-item:nth-child(5) { /* Date Edited */
            flex: 0 0 120px;
            text-align: center;
        }
        .header-item:nth-child(6), .row-item:nth-child(6) { /* Status (was Remarks) */
            flex: 0 0 100px; /* Adjusted width for Status */
            text-align: center;
        }
        .header-item:nth-child(7), .row-item:nth-child(7) { /* Actions */
            flex: 0 0 120px;
            text-align: center;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 5px;
        }
        /* Action Button Styles */
        .edit-project-btn, .delete-btn {
            width: 30px;
            height: 30px;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 0;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            text-decoration: none;
            color: inherit;
            background-color: transparent;
        }
        .edit-project-btn { background-color: #0D47A1; color: white; }
        .delete-btn { background-color: #C62828; color: white; }
        .back-btn {
            display: inline-block;
            background-color: #0d47a1;
            color: #fff;
            padding: 8px 12px;
            text-decoration: none;
            border-radius: 4px;
            margin: 10px;
        }

        /* Make the table horizontally scrollable on small screens */
        @media (max-width: 900px) {
            .dashboard-container {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                padding-bottom: 16px;
            }
            .table-header-custom,
            .table-row-custom {
                min-width: 800px; /* Adjust as needed for all columns to fit */
                width: 800px;
                box-sizing: border-box;
            }
        }

        /* Optional: Style the scrollbar for better visibility */
        .dashboard-container::-webkit-scrollbar {
            height: 8px;
        }
        .dashboard-container::-webkit-scrollbar-thumb {
            background: #c62828;
            border-radius: 4px;
        }
        .dashboard-container::-webkit-scrollbar-track {
            background: #f5f5f5;
        }

        @media (max-width: 700px) {
            .title-left {
                font-size: 12px !important;
            }
            /* Hide dashboard-action-btns on small screens */
            .dashboard-action-btns {
                display: none !important;
            }
            .dropdown-content .mobile-admin-link {
                display: block !important;
            }
        }
        /* Hide mobile admin links by default (desktop) */
        .dropdown-content .mobile-admin-link {
            display: none;
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
                    <?php if ($_SESSION['admin'] == 1): ?>
                        <a href="create_account.php" class="mobile-admin-link">
                            <img src="assets/images/Add_Button.png" alt="Add" style="width:18px;vertical-align:middle;margin-right:6px;">
                            Create Account
                        </a>
                        <a href="manage_accounts.php" class="mobile-admin-link">
                            <img src="assets/images/Manage_account_Icon.png" alt="Manage" style="width:18px;vertical-align:middle;margin-right:6px;">
                            Manage Accounts
                        </a>
                    <?php endif; ?>
                    <a href="logout.php" id="logoutBtn">Log out</a>
                </div>
            </div>
        </div>
    </div>

    <?php if ($_SESSION['admin'] == 1): ?>
    <div class="dashboard-action-btns">
        <button id="goToCreate" type="button" class="create-account-btn green-create-btn">
            <img src="assets/images/Add_Button.png" alt="Add" class="add-btn-icon">
            Create Account
        </button>
        <button id="goToAccounts" type="button" class="manage-account-btn">
            <img src="assets/images/Manage_account_Icon.png" alt="Manage" class="manage-btn-icon">
            Manage Accounts
        </button>
    </div>
    <?php endif; ?>

    <div class="dashboard-container">
            <div class="dashboard-search-bar-wrapper">
        <div class="dashboard-search-bar-inner">
            <input type="text" id="searchInput" class="dashboard-search-bar" placeholder="Search by PR Number or Project Details...">
        </div>
    </div>


        <div class="search-and-add" id="addProjectSection" style="margin-bottom: 20px;">
            <button class="add-pr-button" id="showAddProjectForm">
                <img src="assets/images/Add_Button.png" alt="Add" class="add-pr-icon">
                Add Project
            </button>
        </div>

        <?php
            if (isset($projectError)) {
                echo "<p style='color:red; text-align:center;'>" . htmlspecialchars($projectError) . "</p>";
            }
            if (isset($deleteProjectError)) {
                echo "<p style='color:red; text-align:center;'>" . htmlspecialchars($deleteProjectError) . "</p>";
            }
        ?>

        <div class="table-header-custom">
            <div class="header-item">PR Number</div>
            <div class="header-item">Project Details</div>
            <div class="header-item">Created By</div>
            <div class="header-item">Date Created</div>
            <div class="header-item">Date Edited</div>
            <div class="header-item">Status</div> <div class="header-item">Actions</div>
        </div>

        <?php foreach ($projects as $project): ?>
            <div class="table-row-custom">
                <div class="row-item"><?php echo htmlspecialchars($project['prNumber']); ?></div>
                <div class="row-item"><?php echo htmlspecialchars($project['projectDetails']); ?></div>
                <div class="row-item">
                    <?php
                    // Format: First Initial. Last Name
                    if (!empty($project['firstname']) && !empty($project['lastname'])) {
                        echo htmlspecialchars(substr($project['firstname'], 0, 1) . ". " . $project['lastname']);
                    } else {
                        // Fallback if user data is somehow missing or null
                        echo "N/A";
                    }
                    ?>
                </div>
                <div class="row-item"><?php echo date("m-d-Y", strtotime($project['createdAt'])); ?></div>
                <div class="row-item"><?php echo date("m-d-Y", strtotime($project['editedAt'])); ?></div>
                <div class="row-item">
                    <?php
                    // Determine the correct status to display
                    $currentStatusStage = '';
                    if (!empty($project['current_unsubmitted_stage'])) {
                        $currentStatusStage = $project['current_unsubmitted_stage'];
                    } elseif (!empty($project['last_submitted_stage_if_all_submitted'])) {
                        // If all stages are submitted, show the last submitted one.
                        $currentStatusStage = $project['last_submitted_stage_if_all_submitted'];
                    } elseif (!empty($project['first_stage_name'])) {
                        // If no stage is submitted yet, show the first stage (Purchase Request).
                        $currentStatusStage = $project['first_stage_name'];
                    } else {
                        $currentStatusStage = 'No Stages'; // Fallback for unexpected cases
                    }

                    echo getDisplayStatus($currentStatusStage, $stagesOrder);
                    ?>
                </div>
                <div class="row-item actions">
                    <a href="edit_project.php?projectID=<?php echo $project['projectID']; ?>" class="edit-project-btn" title="Edit Project">📝</a>
                    <?php if ($_SESSION['admin'] == 1): ?>
                        <a href="index.php?deleteProject=<?php echo $project['projectID']; ?>" class="delete-btn"
                            onclick="return confirm('Are you sure you want to delete this project and all its stages?');" title="Delete Project">
                            🗑️
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div id="noResults" style="display:none; text-align:center; font-weight:bold;">No results</div>

    <div id="addProjectModal" class="modal">
        <div class="modal-content">
            <span class="close" id="addProjectClose">&times;</span>
            <h2>Add Project</h2>
            <form id="addProjectForm" action="index.php" method="post">
                <label for="prNumber">Project Number (PR Number)*</label>
                <input type="text" name="prNumber" id="prNumber" required>
                <label for="projectDetails">Project Details*</label>
                <textarea name="projectDetails" id="projectDetails" rows="4" required></textarea>
                <button type="submit" name="addProject">Add Project</button>
            </form>
        </div>
    </div>

    <script>
        // Add Project Modal logic
        const addProjectModal = document.getElementById('addProjectModal');
        const addProjectClose = document.getElementById('addProjectClose');
        document.getElementById('showAddProjectForm').addEventListener('click', function() {
            addProjectModal.style.display = 'block';
        });
        addProjectClose.addEventListener('click', function() {
            addProjectModal.style.display = 'none';
        });
        window.addEventListener('click', function(event) {
            if (event.target === addProjectModal) {
                addProjectModal.style.display = 'none';
            }
        });

        // Button redirections for Create and Manage Accounts.
        document.getElementById('goToCreate') && (document.getElementById('goToCreate').onclick = function() {
            window.location.href = "create_account.php";
        });
        document.getElementById('goToAccounts') && (document.getElementById('goToAccounts').onclick = function() {
            window.location.href = "manage_accounts.php";
        });

        document.getElementById("searchInput").addEventListener("keyup", function() {
        let query = this.value.toLowerCase().trim();
        let rows = document.querySelectorAll(".table-row-custom");
        let visibleCount = 0;

        rows.forEach(row => {
            // Get text content of PR Number (first cell) and Project Details (second cell)
            let prNumber = row.children[0].textContent.toLowerCase();
            let projectDetails = row.children[1].textContent.toLowerCase();

            if (prNumber.includes(query) || projectDetails.includes(query)) {
                row.style.display = "flex";
                visibleCount++;
            } else {
                row.style.display = "none";
            }
        });

        // Show "No results" message if no rows are visible
        const noResultsDiv = document.getElementById("noResults");
        if (visibleCount === 0) {
            noResultsDiv.style.display = "block";
        } else {
            noResultsDiv.style.display = "none";
        }
        });
    </script>
</body>
</html>