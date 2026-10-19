import { Head, Link, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import AppLayout from '@/layouts/app-layout';
import teamRoutes from '@/routes/teams';
import type { SharedTeam } from '@/types/shared';

export default function TeamsIndex({ teams }: { teams: SharedTeam[] }) {
    const personalTeam = teams.find((team) => team.isPersonal);

    return (
        <>
            <Head title="Your teams" />
            <PageHeader
                eyebrow="Workspaces"
                title="Your teams"
                description="Switch to another team workspace."
            />

            <div className="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Teams</CardTitle>
                        <CardDescription>
                            Switch to a team or open its settings.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ul className="divide-y divide-border">
                            {teams.map((team) => (
                                <li
                                    key={team.id}
                                    className="flex min-w-0 flex-wrap items-center gap-2 py-3 md:gap-3"
                                >
                                    <Link
                                        className="min-w-0 font-medium break-words hover:underline"
                                        href={teamRoutes.edit.url({
                                            team: team.slug,
                                        })}
                                    >
                                        {team.name}
                                    </Link>
                                    {team.isPersonal && (
                                        <Badge variant="outline">
                                            personal
                                        </Badge>
                                    )}
                                    {team.isCurrent && (
                                        <Badge variant="success">current</Badge>
                                    )}
                                    <span className="ml-auto text-sm text-muted-foreground">
                                        {team.roleLabel}
                                    </span>
                                    {!team.isCurrent && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    teamRoutes.switch.url({
                                                        team: team.slug,
                                                    }),
                                                )
                                            }
                                        >
                                            Switch
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>

                {personalTeam && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Need a team?</CardTitle>
                            <CardDescription>
                                Turn your personal workspace into a team and
                                your artifacts come with it.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Button asChild variant="outline">
                                <Link
                                    href={teamRoutes.edit.url({
                                        team: personalTeam.slug,
                                    })}
                                >
                                    Set up a team
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

TeamsIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
