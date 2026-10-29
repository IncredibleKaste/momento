import { Head, usePage } from '@inertiajs/react';
import { dashboard } from '@/routes';

export default function Dashboard() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Home" />
            <section className="flex flex-col gap-3 py-10">
                <h1 className="font-serif text-3xl">Home</h1>
                <p className="text-muted-foreground">
                    Welcome, {auth.user?.name}.
                </p>
            </section>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Home',
            href: dashboard(),
        },
    ],
};
