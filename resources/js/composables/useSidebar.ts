import { ref, onMounted } from 'vue';

const sidebarCollapsed = ref(false);
const adminExpanded = ref(true);

const STORAGE_KEYS = {
    collapsed: 'sidebar-collapsed',
    adminExpanded: 'sidebar-admin-expanded',
};

export function useSidebar() {
    const toggleSidebar = () => {
        sidebarCollapsed.value = !sidebarCollapsed.value;
        localStorage.setItem(STORAGE_KEYS.collapsed, String(sidebarCollapsed.value));
    };

    const setSidebarCollapsed = (collapsed: boolean) => {
        sidebarCollapsed.value = collapsed;
        localStorage.setItem(STORAGE_KEYS.collapsed, String(collapsed));
    };

    const toggleAdmin = () => {
        adminExpanded.value = !adminExpanded.value;
        localStorage.setItem(STORAGE_KEYS.adminExpanded, String(adminExpanded.value));
    };

    const setAdminExpanded = (expanded: boolean) => {
        adminExpanded.value = expanded;
        localStorage.setItem(STORAGE_KEYS.adminExpanded, String(expanded));
    };

    onMounted(() => {
        // Load saved state from localStorage
        const savedCollapsed = localStorage.getItem(STORAGE_KEYS.collapsed);
        if (savedCollapsed !== null) {
            sidebarCollapsed.value = savedCollapsed === 'true';
        }

        const savedAdminExpanded = localStorage.getItem(STORAGE_KEYS.adminExpanded);
        if (savedAdminExpanded !== null) {
            adminExpanded.value = savedAdminExpanded === 'true';
        }
    });

    return {
        sidebarCollapsed,
        adminExpanded,
        toggleSidebar,
        setSidebarCollapsed,
        toggleAdmin,
        setAdminExpanded,
    };
}
