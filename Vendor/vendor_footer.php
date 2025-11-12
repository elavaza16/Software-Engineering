</main>
</div>

<script>
    function toggleSidebar() {
        const body = document.body;
        const isCollapsed = body.classList.toggle('sidebar-collapsed');
        // Use a vendor-specific storage key to keep preferences separate, if desired,
        // but for simplicity, 'sidebarState' is fine.
        localStorage.setItem('sidebarState', isCollapsed ? 'collapsed' : 'open');
    }

    window.onload = function() {
        // This is CRUCIAL for rendering the icons (Lucide)
        lucide.createIcons();
        const savedState = localStorage.getItem('sidebarState');
        if (savedState === 'collapsed') {
            document.body.classList.add('sidebar-collapsed');
        }
    };
</script>
</body>
</html>