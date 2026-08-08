import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
};

/**
 * An entry in the sidebar's grouped navigation.
 *
 * `href` is optional because the information architecture is settled ahead of
 * the screens: an item with no href is a section of the ledger that has been
 * designed but not built, and renders as an inert placeholder rather than a
 * link to nowhere. Giving it a route is what "shipping" that screen looks like.
 */
export type NavSectionItem = {
    title: string;
    href?: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
};

export type NavSection = {
    title: string;
    items: NavSectionItem[];
};
