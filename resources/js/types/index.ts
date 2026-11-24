import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    auth: Auth;
    flash: { success: string | null; error: string | null };
    errors: Record<string, string>;
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    balance_nanos: number;
    is_admin: boolean;
}

export type BreadcrumbItemType = BreadcrumbItem;

export type GenerationStatus = 'submitting' | 'queued' | 'processing' | 'succeeded' | 'failed';

export interface Usage {
    input_text_tokens: number;
    input_image_tokens: number;
    output_tokens: number;
    reasoning_tokens: number;
    total_tokens: number;
}

/** Money is always integer nanodollars (1 USD = 1,000,000,000). */
export interface Generation {
    id: string;
    pixelforge_id: string | null;
    status: GenerationStatus;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    estimated_cost_nanos: number;
    max_cost_nanos: number;
    charged_nanos: number | null;
    provider_cost_nanos: number | null;
    usage: Usage | null;
    image_url: string | null;
    image_expired: boolean;
    error_code: string | null;
    error_message: string | null;
    retry_reason: string | null;
    submit_attempts: number;
    created_at: string;
    started_at: string | null;
    completed_at: string | null;
}

export interface Pricing {
    rates: Record<string, string>;
    sizes: string[];
    qualities: string[];
    output_tokens: Record<string, Record<string, number>>;
    reasoning: Record<string, { min: number; max: number }>;
    tokens_per_reference_image: number;
}

export interface Estimate {
    low_nanos: number;
    expected_nanos: number;
    max_nanos: number;
    tokens: {
        input_text: { estimate: number; min: number; max: number };
        input_image: number;
        output: number;
        reasoning: { min: number; max: number };
    };
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
}
