/**
 * PatYourSelf — DS primitives (Icon, Button, IconButton, Chip, Avatar,
 * Composer, Eyebrow). Ported from the design-system kit; styling lives in
 * the `py-*` classes in patyourself.css. Icons are lucide, looked up by the
 * kebab-case names the design used.
 */
import {
  ArrowUp, Check, Footprints, GitBranch, MessageCircle, Minus, Moon, ShieldCheck,
  Sun, TrendingDown, TrendingUp, type LucideIcon,
} from 'lucide-react';
import { useState, type CSSProperties, type ReactNode } from 'react';

const ICONS: Record<string, LucideIcon> = {
  'arrow-up': ArrowUp,
  'check': Check,
  'footprints': Footprints,
  'git-branch': GitBranch,
  'message-circle': MessageCircle,
  'minus': Minus,
  'moon': Moon,
  'shield-check': ShieldCheck,
  'sun': Sun,
  'trending-down': TrendingDown,
  'trending-up': TrendingUp,
};

export function Icon({ name, size = 20, stroke = 2, className = '', style }: {
  name: string; size?: number; stroke?: number; className?: string; style?: CSSProperties;
}) {
  const Cmp = ICONS[name];
  if (!Cmp) return null;
  return (
    <span className={`py-icon ${className}`} style={{ width: size, height: size, display: 'inline-flex', ...style }}>
      <Cmp size={size} strokeWidth={stroke} />
    </span>
  );
}

type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger';
export function Button({ variant = 'primary', size = 'md', icon, iconRight, children, full, onClick, disabled, type = 'button' }: {
  variant?: ButtonVariant; size?: 'sm' | 'md'; icon?: string; iconRight?: string;
  children?: ReactNode; full?: boolean; onClick?: () => void; disabled?: boolean; type?: 'button' | 'submit';
}) {
  const cls = `py-btn py-btn--${variant} py-btn--${size}${full ? ' py-btn--full' : ''}`;
  return (
    <button className={cls} onClick={onClick} disabled={disabled} type={type}>
      {icon && <Icon name={icon} size={size === 'sm' ? 16 : 18} />}
      {children && <span>{children}</span>}
      {iconRight && <Icon name={iconRight} size={size === 'sm' ? 16 : 18} />}
    </button>
  );
}

export function IconButton({ name, label, onClick, variant = 'ghost', size = 20 }: {
  name: string; label: string; onClick?: () => void; variant?: 'ghost' | 'solid'; size?: number;
}) {
  return (
    <button className={`py-iconbtn py-iconbtn--${variant}`} onClick={onClick} aria-label={label} title={label}>
      <Icon name={name} size={size} />
    </button>
  );
}

export function Chip({ children, tone = 'neutral', icon, active, onClick }: {
  children: ReactNode; tone?: string; icon?: string; active?: boolean; onClick?: () => void;
}) {
  const cls = `py-chip py-chip--${tone}${active ? ' is-active' : ''}${onClick ? ' py-chip--btn' : ''}`;
  if (onClick) {
    return (
      <button className={cls} onClick={onClick}>
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

export function Avatar({ kind = 'coach', initial = 'Y', size = 36 }: {
  kind?: 'coach' | 'user'; initial?: string; size?: number;
}) {
  if (kind === 'coach') {
    return (
      <span className="py-avatar py-avatar--coach" style={{ width: size, height: size }}>
        <svg viewBox="0 0 40 40" width={size * 0.62} height={size * 0.62} aria-hidden="true">
          <path d="M20 8 a12 12 0 1 1 -10.4 6" stroke="currentColor" strokeWidth="3.4" strokeLinecap="round" fill="none" />
          <path d="M20 8 l-4.8 -2 m4.8 2 l-2 4.8" stroke="currentColor" strokeWidth="3.4" strokeLinecap="round" strokeLinejoin="round" fill="none" />
          <circle cx="20" cy="20" r="3.2" fill="currentColor" />
        </svg>
      </span>
    );
  }
  return (
    <span className="py-avatar py-avatar--user" style={{ width: size, height: size, fontSize: size * 0.4 }}>{initial}</span>
  );
}

export function Composer({ placeholder = "Tell your coach what's on your mind…", value, onChange, onSend }: {
  placeholder?: string; value?: string; onChange?: (v: string) => void; onSend?: (v: string) => void;
}) {
  const controlled = value !== undefined;
  const [local, setLocal] = useState('');
  const val = controlled ? value : local;
  const set = (v: string) => (controlled ? onChange?.(v) : setLocal(v));
  const send = () => {
    if (val.trim() && onSend) onSend(val.trim());
    set('');
  };
  return (
    <div className="py-composer">
      <input
        className="py-composer__input"
        value={val}
        placeholder={placeholder}
        onChange={(e) => set(e.target.value)}
        onKeyDown={(e) => { if (e.key === 'Enter') send(); }}
      />
      <button className="py-composer__send" aria-label="Send" disabled={!val.trim()} onClick={send}>
        <Icon name="arrow-up" size={20} />
      </button>
    </div>
  );
}

export function Eyebrow({ children, color }: { children: ReactNode; color?: string }) {
  return <div className="py-eyebrow" style={color ? { color } : undefined}>{children}</div>;
}
