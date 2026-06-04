import { Link } from '@inertiajs/react';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div
            className="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10"
            style={{
                background:
                    'radial-gradient(120% 80% at 50% -10%, color-mix(in oklch, var(--primary) 10%, transparent) 0%, transparent 55%), var(--background)',
            }}
        >
            <div className="w-full max-w-sm">
                <div className="flex flex-col items-center gap-2">
                    <Link href={home()} className="flex items-center gap-2.5">
                        <img
                            src="/patyourself/app-icon.svg"
                            alt=""
                            className="size-9 rounded-[10px]"
                        />
                        <span className="font-display text-2xl font-extrabold tracking-[-0.03em] text-foreground">
                            patyourself
                        </span>
                    </Link>
                </div>

                <div className="mt-6 rounded-xl border border-border bg-card p-6 shadow-sm sm:p-8">
                    <div className="mb-6 space-y-1.5 text-center">
                        <h1 className="font-display text-xl font-bold tracking-tight text-foreground">
                            {title}
                        </h1>
                        <p className="text-sm text-muted-foreground">{description}</p>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
