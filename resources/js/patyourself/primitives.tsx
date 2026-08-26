/**
 * PatYourSelf — DS primitives (Icon, Button, IconButton, Chip, Eyebrow).
 * Ported from the design-system kit; styling lives in the `py-*` classes in
 * patyourself.css. Icons are lucide, looked up by the kebab-case names the
 * design used.
 */
import {
    ArrowUp,
    Bell,
    Check,
    Footprints,
    GitBranch,
    MessageCircle,
    Minus,
    Moon,
    ShieldCheck,
    Sun,
    TrendingDown,
    TrendingUp,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';

const ICONS: Record<string, LucideIcon> = {
    'arrow-up': ArrowUp,
    bell: Bell,
    check: Check,
    footprints: Footprints,
    'git-branch': GitBranch,
    'message-circle': MessageCircle,
    minus: Minus,
    moon: Moon,
    'shield-check': ShieldCheck,
    sun: Sun,
    'trending-down': TrendingDown,
    'trending-up': TrendingUp,
};

export function Icon({
    name,
    size = 20,
    stroke = 2,
    className = '',
    style,
}: {
    name: string;
    size?: number;
    stroke?: number;
    className?: string;
    style?: CSSProperties;
}) {
    const Cmp = ICONS[name];

    if (!Cmp) {
        return null;
    }

    return (
        <span
            className={`py-icon ${className}`}
            style={{
                width: size,
                height: size,
                display: 'inline-flex',
                ...style,
            }}
        >
            <Cmp size={size} strokeWidth={stroke} />
        </span>
    );
}

type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger';
export function Button({
    variant = 'primary',
    size = 'md',
    icon,
    iconRight,
    children,
    full,
    onClick,
    disabled,
    type = 'button',
}: {
    variant?: ButtonVariant;
    size?: 'sm' | 'md';
    icon?: string;
    iconRight?: string;
    children?: ReactNode;
    full?: boolean;
    onClick?: () => void;
    disabled?: boolean;
    type?: 'button' | 'submit';
}) {
    const cls = `py-btn py-btn--${variant} py-btn--${size}${full ? ' py-btn--full' : ''}`;

    return (
        <button
            className={cls}
            onClick={onClick}
            disabled={disabled}
            type={type}
        >
            {icon && <Icon name={icon} size={size === 'sm' ? 16 : 18} />}
            {children && <span>{children}</span>}
            {iconRight && (
                <Icon name={iconRight} size={size === 'sm' ? 16 : 18} />
            )}
        </button>
    );
}

export function IconButton({
    name,
    label,
    onClick,
    variant = 'ghost',
    size = 20,
}: {
    name: string;
    label: string;
    onClick?: () => void;
    variant?: 'ghost' | 'solid';
    size?: number;
}) {
    return (
        <button
            type="button"
            className={`py-iconbtn py-iconbtn--${variant}`}
            onClick={onClick}
            aria-label={label}
            title={label}
        >
            <Icon name={name} size={size} />
        </button>
    );
}

export function Chip({
    children,
    tone = 'neutral',
    icon,
    active,
    onClick,
}: {
    children: ReactNode;
    tone?: string;
    icon?: string;
    active?: boolean;
    onClick?: () => void;
}) {
    const cls = `py-chip py-chip--${tone}${active ? ' is-active' : ''}${onClick ? ' py-chip--btn' : ''}`;

    if (onClick) {
        return (
            <button type="button" className={cls} onClick={onClick}>
                {icon && <Icon name={icon} size={14} />}
                {children}
            </button>
        );
    }

    return (
        <span className={cls}>
            {icon && <Icon name={icon} size={14} />}
            {children}
        </span>
    );
}

export function Eyebrow({
    children,
    color,
}: {
    children: ReactNode;
    color?: string;
}) {
    return (
        <div className="py-eyebrow" style={color ? { color } : undefined}>
            {children}
        </div>
    );
}
