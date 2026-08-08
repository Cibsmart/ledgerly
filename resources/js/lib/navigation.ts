import {
    ArrowLeftRight,
    BookOpenText,
    CalendarRange,
    Landmark,
    LayoutGrid,
    ListTree,
    Receipt,
    Scale,
    TrendingUp,
    Users,
    WalletCards,
} from 'lucide-react';
import { dashboard } from '@/routes';
import type { NavItem, NavSection } from '@/types';

/**
 * The application's navigation, defined once and consumed by both the sidebar
 * and the header layout.
 *
 * Items without an `href` are screens that do not exist yet. They are listed
 * anyway: the grouping is a design decision made in Phase 0 so that later
 * phases hang their screens on a settled structure instead of renegotiating it
 * each time. To ship one, import its Wayfinder route and add `href` — nothing
 * else needs to change.
 */
export const navSections: NavSection[] = [
    {
        title: 'Ledger',
        items: [
            { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
            { title: 'Chart of Accounts', icon: ListTree },
            { title: 'Journal Entries', icon: BookOpenText },
            { title: 'Periods', icon: CalendarRange },
        ],
    },
    {
        title: 'Records',
        items: [
            { title: 'Financial Accounts', icon: Landmark },
            { title: 'Transactions', icon: ArrowLeftRight },
            { title: 'Contacts', icon: Users },
            { title: 'Receipts', icon: Receipt },
        ],
    },
    {
        title: 'Reports',
        items: [
            { title: 'Trial Balance', icon: Scale },
            { title: 'Income Statement', icon: TrendingUp },
            { title: 'Balance Sheet', icon: WalletCards },
        ],
    },
];

/**
 * The flat list of screens that actually exist, for the horizontal header
 * layout, which has no room to render placeholders.
 */
export const availableNavItems: NavItem[] = navSections
    .flatMap((section) => section.items)
    .filter((item): item is NavItem => item.href !== undefined);
