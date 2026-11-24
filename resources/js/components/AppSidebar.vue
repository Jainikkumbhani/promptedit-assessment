<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import BalanceChip from '@/components/studio/BalanceChip.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { BarChart3, Coins, History, Sparkles } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();

const mainNavItems: NavItem[] = [
    { title: 'Studio', href: route('studio'), icon: Sparkles },
    { title: 'History', href: route('generations.index'), icon: History },
    { title: 'Credits', href: route('credits.index'), icon: Coins },
];

const operatorNavItems = computed<NavItem[]>(() =>
    page.props.auth.user?.is_admin ? [{ title: 'Operator report', href: route('admin.report'), icon: BarChart3 }] : [],
);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('studio')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain label="Create" :items="mainNavItems" />
            <NavMain v-if="operatorNavItems.length" label="Operators" :items="operatorNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <BalanceChip />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
