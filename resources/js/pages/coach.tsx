import { Head } from '@inertiajs/react';
import { PatYourSelfApp } from '@/patyourself/shell';

/**
 * PatYourSelf desktop app — Coach screen + shell. Renders standalone
 * (layout: null in app.tsx) since it draws its own deskframe + sidenav.
 */
export default function Coach() {
  return (
    <>
      <Head title="patyourself" />
      <PatYourSelfApp />
    </>
  );
}
