<?php
/**
 * customer_template_header.php
 *
 * @var string $current_view The current dashboard view (e.g., 'home', 'search', 'history').
 * @var string $customer_email The email address of the logged-in customer.
 * @var float $customer_balance The current wallet balance of the customer.
 * @var string $status_message The message from a previous action (e.g., profile update).
 * @var string $status_type The type of status (e.g., 'success' or 'error').
 */

// Note: This file relies on variables defined in customer_dashboard.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Dashboard - AutoHub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap');
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8fafc;
            transition: margin-left 0.3s ease-in-out;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: 200px 1fr;
            min-height: 100vh;
            transition: grid-template-columns 0.3s ease-in-out;
        }

        .sidebar-collapsed .dashboard-grid {
            grid-template-columns: 70px 1fr;
        }

        .sidebar {
            background-color: #0f172a;
            color: #f8fafc;
            padding: 2rem 0;
            position: fixed;
            height: 100%;
            width: 200px;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            transition: width 0.3s ease-in-out;
            overflow-x: hidden;
        }

        .sidebar-collapsed .sidebar {
            width: 70px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 0.6rem 1rem;
            margin: 0.5rem 0;
            transition: background-color 0.2s, color 0.2s, padding 0.3s;
            border-left: 4px solid transparent;
            white-space: nowrap;
            font-size: 0.875rem;
        }

        .sidebar-collapsed .nav-link {
            padding-left: 1rem;
            padding-right: 1rem;
            justify-content: center;
        }

        .sidebar-text {
            transition: opacity 0.3s ease-in-out;
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
        .nav-active {
            background-color: #1e293b;
            border-left-color: #10B981;
            color: #10B981;
            font-weight: 600;
        }
        .nav-active .nav-icon {
            color: #10B981;
        }

        .main-content {
            grid-column: 2 / 3;
            padding: 2rem;
        }

        .business-card {
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .business-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
        }

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
        <div class="px-3 mb-6 flex items-center justify-between">
            <div class="sidebar-text">
                <h1 class="text-2xl font-extrabold text-emerald-500">AutoHub</h1>
                <p class="text-xs text-gray-400 mt-1">Customer Portal</p>
            </div>
            <button onclick="toggleSidebar()" class="text-gray-400 hover:text-emerald-500 transition duration-200 p-2 rounded-full">
                <i data-lucide="menu" class="w-6 h-6"></i>
            </button>
        </div>

        <nav>
            <a href="?view=home" class="nav-link <?= $current_view === 'home' ? 'nav-active' : 'text-gray-300' ?>">
                <i data-lucide="layout-dashboard" class="w-5 h-5 mr-3 nav-icon"></i>
                <span class="sidebar-text">Dashboard</span>
            </a>
            <a href="?view=search" class="nav-link <?= $current_view === 'search' ? 'nav-active' : 'text-gray-300' ?>">
                <i data-lucide="search" class="w-5 h-5 mr-3 nav-icon"></i>
                <span class="sidebar-text">Search & Find</span>
            </a>
            <a href="?view=history" class="nav-link <?= $current_view === 'history' ? 'nav-active' : 'text-gray-300' ?>">
                <i data-lucide="history" class="w-5 h-5 mr-3 nav-icon"></i>
                <span class="sidebar-text">Transaction History</span>
            </a>

        </nav>

        <div class="absolute bottom-6 left-0 right-0 px-3">
            <div class="border-t border-gray-700 pt-4 mb-3 sidebar-text">
                <p class="text-sm font-semibold text-gray-300"><?= htmlspecialchars($customer_email) ?></p>
            </div>
            <a href="../index.html" class="flex items-center text-red-400 hover:text-red-300 text-sm font-medium nav-link justify-start">
                <i data-lucide="log-out" class="w-5 h-5 mr-2"></i>
                <span class="sidebar-text">Log Out</span>
            </a>
        </div>
    </aside>

    <main class="main-content">

        <header class="mb-8 flex justify-between items-center">
            <h2 class="text-3xl font-bold text-gray-900">
                <?= $current_view === 'home' ? 'Welcome Back!' : (ucwords($current_view) . ' View') ?>
            </h2>
            <div class="flex items-center space-x-4">
                <div class="bg-blue-500 text-white rounded-lg px-4 py-2 font-semibold flex items-center shadow-md">
                    <i data-lucide="wallet" class="w-5 h-5 mr-2"></i>
                    KES <?= number_format($customer_balance, 2) ?>
                </div>

                <a href="?view=profile" class="text-gray-500 hover:text-gray-700">
                    <i data-lucide="circle-user" class="w-8 h-8"></i>
                </a>
            </div>
        </header>

        <?php if (!empty($status_message)): ?>
            <div class="p-4 rounded-lg mb-6 <?= $status_type === 'success' ? 'bg-green-100 text-green-800 border-green-300' : 'bg-red-100 text-red-800 border-red-300' ?> border" role="alert">
                <p class="font-semibold"><?= htmlspecialchars($status_message) ?></p>
            </div>
        <?php endif; ?>