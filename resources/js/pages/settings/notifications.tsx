import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import NotificationsController from '@/actions/App/Http/Controllers/Settings/NotificationsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/notifications';

type EmailReminderMode = 'off' | 'digest' | 'every_cue';

const modeCopy: Record<
    EmailReminderMode,
    { title: string; description: string }
> = {
    off: {
        title: 'Off',
        description:
            "You won't receive habit reminders by email. Your in-app inbox still receives every cue.",
    },
    digest: {
        title: 'Daily digest',
        description: 'One email each day, at the local time you choose below.',
    },
    every_cue: {
        title: 'Every cue',
        description: 'A separate email each time a habit cue fires.',
    },
};

export default function Notifications({
    emailReminders,
    digestTime,
    modes,
}: {
    emailReminders: string;
    digestTime: string;
    modes: string[];
}) {
    const [selectedMode, setSelectedMode] = useState(emailReminders);

    return (
        <>
            <Head title="Notification settings" />

            <h1 className="sr-only">Notification settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Notifications"
                    description="Choose how you'd like to hear about your habit cues by email"
                />

                <Form
                    {...NotificationsController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <fieldset className="grid gap-4">
                                <legend className="sr-only">
                                    Email reminders
                                </legend>

                                {modes.map((mode) => (
                                    <div
                                        key={mode}
                                        className="flex items-start gap-3"
                                    >
                                        <input
                                            type="radio"
                                            id={`email_reminders_${mode}`}
                                            name="email_reminders"
                                            value={mode}
                                            checked={selectedMode === mode}
                                            onChange={() =>
                                                setSelectedMode(mode)
                                            }
                                            className="mt-1"
                                        />

                                        <div className="grid gap-0.5">
                                            <Label
                                                htmlFor={`email_reminders_${mode}`}
                                            >
                                                {modeCopy[
                                                    mode as EmailReminderMode
                                                ]?.title ?? mode}
                                            </Label>

                                            <p className="text-sm text-muted-foreground">
                                                {
                                                    modeCopy[
                                                        mode as EmailReminderMode
                                                    ]?.description
                                                }
                                            </p>
                                        </div>
                                    </div>
                                ))}

                                <InputError
                                    className="mt-2"
                                    message={errors.email_reminders}
                                />
                            </fieldset>

                            {selectedMode === 'digest' && (
                                <div className="grid gap-2">
                                    <Label htmlFor="digest_time">
                                        Digest time
                                    </Label>

                                    <Input
                                        id="digest_time"
                                        type="time"
                                        className="mt-1 block w-full"
                                        defaultValue={digestTime}
                                        name="digest_time"
                                        required
                                    />

                                    <InputError
                                        className="mt-2"
                                        message={errors.digest_time}
                                    />
                                </div>
                            )}

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-notifications-button"
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

Notifications.layout = {
    breadcrumbs: [
        {
            title: 'Notification settings',
            href: edit(),
        },
    ],
};
