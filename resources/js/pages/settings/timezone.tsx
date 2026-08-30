import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import TimezoneController from '@/actions/App/Http/Controllers/Settings/TimezoneController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/timezone';

const browserTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

export default function Timezone({
    timezone,
    timezones,
}: {
    timezone: string;
    timezones: string[];
}) {
    const [selected, setSelected] = useState(timezone);

    return (
        <>
            <Head title="Timezone settings" />

            <h1 className="sr-only">Timezone settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Timezone"
                    description="Every schedule in your notebook is worked out from this"
                />

                <Form
                    {...TimezoneController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="timezone">Timezone</Label>

                                <select
                                    id="timezone"
                                    name="timezone"
                                    value={selected}
                                    onChange={(e) =>
                                        setSelected(e.target.value)
                                    }
                                    className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                                >
                                    {timezones.map((tz) => (
                                        <option key={tz} value={tz}>
                                            {tz}
                                        </option>
                                    ))}
                                </select>

                                <InputError
                                    className="mt-2"
                                    message={errors.timezone}
                                />
                            </div>

                            {browserTimezone !== selected && (
                                <p className="text-sm text-muted-foreground">
                                    Your browser reports your timezone as{' '}
                                    {browserTimezone}.{' '}
                                    <Button
                                        type="button"
                                        variant="link"
                                        size="sm"
                                        onClick={() =>
                                            setSelected(browserTimezone)
                                        }
                                    >
                                        Use this
                                    </Button>
                                </p>
                            )}

                            <p className="text-sm text-muted-foreground">
                                Changing your timezone moves every future
                                occasion to this local time going forward.
                                Occasions you have already logged stay exactly
                                where they happened — changing your timezone
                                never rewrites history.
                            </p>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-timezone-button"
                                >
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

Timezone.layout = {
    breadcrumbs: [
        {
            title: 'Timezone settings',
            href: edit(),
        },
    ],
};
