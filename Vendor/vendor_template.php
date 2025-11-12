<?php
// This file assumes session_start() and all necessary variables
// ($current_view, $vendor_name, etc.) are set BEFORE it is included.

// Ensure these variables are set, providing fallbacks
$current_view = $current_view ?? 'unknown';
$vendor_name = $vendor_name ?? 'Vendor Portal';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $vendor_name ?> - <?= ucwords($current_view) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        /* Replicate the common CSS styles here for the layout and sidebar */
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap');
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8fafc;
            transition: margin-left 0.3s ease-in-out;
        }

        /* Main Grid Layout for Desktop */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 240px 1fr;
            min-height: 100vh;
            transition: grid-template-columns 0.3s ease-in-out;
        }
        .sidebar-collapsed .dashboard-grid {
            grid-template-columns: 90px 1fr;
        }

        /* Styles for the Sidebar */
        .sidebar {
            background-color: #0f172a;
            color: #f8fafc;
            padding: 2rem 0;
            position: fixed;
            height: 100%;
            width: 240px;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            transition: width 0.3s ease-in-out;
            overflow-x: hidden;
            z-index: 10;
        }
        .sidebar-collapsed .sidebar {
            width: 90px;
        }
        .nav-link {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            margin: 0.5rem 0;
            transition: background-color 0.2s, color 0.2s, padding 0.3s;
            border-left: 4px solid transparent;
            white-space: nowrap;
        }
        .sidebar-collapsed .nav-link {
            padding-left: 1.75rem;
            padding-right: 1.75rem;
            justify-content: center;
        }
        .sidebar-text {
            transition: opacity 0.3s ease-in-out, width 0.3s ease-in-out;
        }
        .sidebar-collapsed .sidebar-text {
            opacity: 0;
            width: 0;
            overflow: hidden;
            display: none;
        }
        .nav-link:hover {
            background-color: #1e293b;
        }
        /* Active color: Orange-500 (#f97316) */
        .nav-active {
            background-color: #1e293b;
            border-left-color: #f97316;
            color: #f97316;
            font-weight: 600;
        }
        .nav-active .nav-icon {
            color: #f97316;
        }

        .main-content {
            grid-column: 2 / 3;
            padding: 2rem;
            background-color: #f8fafc;
        }

        /* Alerts */
        .alert {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 0.5rem;
            font-weight: 600;
            border: 1px solid transparent;
        }
        .alert-danger { background-color: #fef2f2; color: #991b1b; border-color: #fecaca; }
        .alert-success { background-color: #D4EDDA; color: #155724; border-color: #C3E6CB; }
        .alert-warning { background-color: #FFF3CD; color: #856404; border-color: #FFEEBA; }


        /* Responsive adjustments */
        @media (max-width: 768px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            .sidebar {
                display: none;
            }
            .main-content {
                grid-column: 1 / 2;
                padding: 1rem;
            }
        }
    </style>
</head>
<body>

<div class="dashboard-grid" id="dashboardGrid">

    <aside class="sidebar" id="sidebar">
        <div class="px-6 mb-8 flex items-center justify-between">
            <div class="sidebar-text">
                <h1 class="text-3xl font-extrabold text-orange-500">PartStock</h1>
                <p class="text-xs text-gray-400 mt-1">Vendor Portal</p>
            </div>
            <button onclick="toggleSidebar()" class="text-gray-400 hover:text-orange-500 transition duration-200 p-2 rounded-full">
                <i data-lucide="menu" class="w-6 h-6"></i>
            </button>
        </div>

        <nav>
            <a href="vendor_dashboard.php" class="nav-link <?= $current_view === 'dashboard' ? 'nav-active' : 'text-gray-300' ?>">
                <i data-lucide="layout-dashboard" class="w-5 h-5 mr-3 nav-icon"></i>
                <span class="sidebar-text">Dashboard</span>
            </a>
            <a href="parts.php" class="nav-link <?= $current_view === 'parts' ? 'nav-active' : 'text-gray-300' ?>">
                <i data-lucide="package" class="w-5 h-5 mr-3 nav-icon"></i>
                <span class="sidebar-text">Manage Spare Parts</span>
            </a>
            <a href="transactions.php" class="nav-link <?= $current_view === 'transactions' ? 'nav-active' : 'text-gray-300' ?>">
                <i data-lucide="receipt" class="w-5 h-5 mr-3 nav-icon"></i>
                <span class="sidebar-text">Orders & Shipments</span>
            </a>
            <!-- <a href="transactions.php" class="nav-link <?= $current_view === 'transactions' ? 'nav-active' : 'text-gray-300' ?>">
                    <i data-lucide="receipt" class="w-5 h-5 mr-3 nav-icon"></i>
                    <span class="sidebar-text">Transactions</span>
                </a> -->
            <a href="vendor_profile.php" class="nav-link <?= $current_view === 'profile' ? 'nav-active' : 'text-gray-300' ?>">
                <i data-lucide="settings" class="w-5 h-5 mr-3 nav-icon"></i>
                <span class="sidebar-text">Profile Settings</span>
            </a>
        </nav>

        <div class="absolute bottom-6 left-0 right-0 px-6">
            <div class="border-t border-gray-700 pt-4 mb-3 sidebar-text">
                <p class="text-sm font-semibold text-gray-300"><?= $vendor_name ?></p>
            </div>
            <a href="../index.html" class="flex items-center text-red-400 hover:text-red-300 text-sm font-medium nav-link justify-start">
                <i data-lucide="log-out" class="w-5 h-5 mr-2"></i>
                <span class="sidebar-text">Log Out</span>
            </a>
        </div>
    </aside>
    <main class="main-content">