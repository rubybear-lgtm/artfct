---
slug: where-a-shared-ai-artifact-library-lives
title: Where should a shared AI artifact library live?
description: A shared AI artifact library can sit at one hosted address every member signs in to, or on a machine the team runs. Four jobs decide the placement.
date: 2026-09-29
tag: comparisons
published: false
surface: comparison
register: glean-formal
target: shared AI artifact library
---

A shared AI artifact library for what an AI tool makes can sit at one hosted address that every member signs in to, or on a machine the team runs itself. Four jobs decide the choice between them: who can reach the library, what has to be installed on each machine, who maintains it, and whether one person's connection covers a colleague.

## What are the two places a shared artifact library can live?

A shared artifact library has to sit somewhere, and the placement decides most of what follows. One arrangement puts it at a hosted address that every member signs in to. The other puts it on a machine that the team operates and maintains for itself.

Artfct is the hosted arrangement. Tools connect through a [remote MCP server](https://artfct.dev/docs) at one address, members reach it by signing in through a browser, and no Artfct server or local component is installed or kept running on a member's machine. The same address serves every member of the workspace, so there is no on-machine copy to keep in step.

The alternative is a library that the team runs itself, on hardware it controls. Nothing leaves the network unless the team opens a route, and the team owns every upgrade, backup and certificate that keeps it answering. That control is real, and the work behind it is real as well.

The two are not separated by a count of features. Four jobs decide the placement: who can reach the library, what has to be installed on each machine, who maintains it, and whether one person's connection stands in for a colleague's.

## Who can reach the library?

A hosted address answers anything that speaks the [remote transport the Model Context Protocol defines](https://modelcontextprotocol.io/specification/2025-06-18/basic/transports) over HTTP. A client that runs in a browser reaches the same server as a client that runs on a laptop, because neither of them hosts the library. A tool that can only start programs on the machine it sits on cannot connect to a hosted address at all.

A library the team runs itself is reachable only where the team has run the wiring. A machine on the same network opens it easily enough. A colleague working from elsewhere needs a route to that machine before a browser shows anything.

The two answers differ rather than compete. One is reachable from wherever a member can sign in, and the other from wherever the team has decided the network should extend.

The [documentation](https://artfct.dev/docs) lists which AI tools connect and the status of each one, so a team can check its own clients before deciding.

## What has to be installed on each machine?

On the hosted arrangement, the answer is one approval per person rather than software the team maintains. A member signs in through a browser and authorizes the tool, and the library is there from that machine. No Artfct server and no local component is installed or kept running.

A library the team runs itself lives on the machines that host it. Every host needs the software kept current, and every machine that talks to it needs to be pointed at it. When the version changes, someone is responsible for each host that runs it, including the one nobody remembers.

That obligation grows with the number of hosts rather than the number of members. A single machine is therefore easy to run, and several machines become a commitment with a schedule attached.

The connection itself is a step a member takes once per tool. The [full reference](https://artfct.dev/llms-full.txt) lists every tool that connects and what each one can be asked to do.

## Who keeps it running?

Behind a hosted address, the operator carries the upgrades, the storage and the work of staying answerable. A member's part ends at keeping a connection authorized, and an administrator can revoke a connected tool when that person leaves.

A library the team runs itself hands that work back to the team. Someone patches it, someone holds the keys, and someone notices when the storage fills. None of that can be delegated to a vendor who is not in the building.

The trade is legible. A team that already runs services for itself is adding one more to a practice it has. A team with no operations habit is taking one on.

The cost of that ownership is wider than the software. A library the team runs itself answers only to the people who can reach the network it sits on, and a machine that has been switched off is a library that nobody can open.

## Does one person's connection cover a colleague?

Connections to a hosted library are per person. Each member authorizes their own tool, and one member's connection does not carry another. The credential belongs to the person who signed in, and it reaches the library rather than the machine.

That is deliberate. An administrator can revoke one person's tool without disturbing anyone else's, and the list of connected tools stays a list of decisions somebody made rather than a shared secret.

A library that the team runs itself can centralise this instead. One credential held on the machine can serve everyone who reaches it, which is less to manage when the group is small and the network is closed.

The difference is where the identity sits, not whether either arrangement is careful. A connection per person is a governance choice, and a shared credential on a private machine is a simplicity choice.

## How do the two placements compare at a glance?

The table below sets out the four deciding jobs, and adds the storage bounds, because size is where a migration usually starts.

| Question | A hosted address | A library the team runs itself |
| --- | --- | --- |
| Who can reach it | Any member who can sign in, from a browser or from a client that can reach a hosted server over the network | Machines the team has given a route to, and whoever holds the credential for them |
| What is installed on a member's machine | No Artfct server or local component stays on the machine; each person approves the connection once through a browser | The library on every host, kept current by the team |
| Who maintains it | The operator behind the address | The team, across every host that runs it |
| Whose connection covers a colleague | Nobody's; each member authorizes their own tool | A credential on the machine can serve every user of it |
| Storage bounds | A single page up to 1 MB; a bundle up to 500 files, capped at the lower of 8 MB and the plan ceiling (5 MB on the free plan) | Whatever the team provides on its own hardware |

## When is a library the team runs itself the better choice?

One case is unambiguous. A person with private files, nothing shared and nobody else to serve gains nothing from an address that other people can sign in to.

The machine is already where the work has to be. A single host answers the browser on the same desk, holds what that machine holds, and needs nobody else's authorization to exist. Publishing to a shared artifact library adds a step to a job that ended when the file was written.

The second case is a team with a rule it does not bend. Where a standing policy keeps work on hardware the team owns, the operational cost of running the library is not a reason to reopen the policy.

The third is a team that already runs services and has a person who does it. One more service on hardware that team knows is an addition to a practice, not a new practice.

## When does a hosted address earn its place?

The hosted arrangement earns its place when the library has to be somewhere the members are not.

The first sign is a second person. As soon as a colleague needs to open what a tool made, the library has to answer without the machine of origin being awake. An address does that from any browser that opens, and one address serves every member on every machine.

The second sign is a browser client. A page has to render for people who will never run anything themselves, and a browser-based client reaches the same server as a client on a laptop. The library does not care which one asked.

The third sign is the work of membership. No Artfct server or local component is installed on a member's machine, so adding a colleague is a sign-in rather than a machine to configure, and removing one is a revocation rather than a search for a laptop.

The fourth is the boundary between artifacts. Each artifact is served from its own origin under a token scoped to it, so one page cannot read another even when both belong to the same team. The mechanics are set out in [who sees what your AI makes](https://artfct.dev/blog/who-sees-what-your-ai-makes).

Neither arrangement is the general answer. The signs above are the ones that pull a library toward an address, and the section before this one lists the cases that pull it toward a machine.

## What this does not do

- Artfct is offered as the hosted arrangement. There is no version for a team to install and run on its own machine.
- Connections are per person. One member's connection does not authorize another, and each tool asks that member to sign in.
- A single page is capped at 1 MB. A bundle is capped at 500 files, and in total at the lower of 8 MB and the plan ceiling: 5 MB on the free plan, 10 MB on team, 25 MB on enterprise.
- Sharing is one of three levels rather than a per-person permission list: team, private or public, plus the owner and the team administrators. Public links can be switched off for the whole team by an administrator, and a publish that asks for one is refused while that setting is off.
- A tool that can only start programs on the machine it runs on cannot connect to a hosted address.

## Questions

### Does a hosted library mean the pages are public?

No. A page arrives visible to the workspace unless the publisher names another level. A private page is limited to its owner and the team administrators, and a public one opens for anyone holding the link, unless an administrator has switched public links off for the team.

### Can a team run its own instance of Artfct?

No. Artfct is offered as a hosted address only. A team that needs a library on its own hardware runs something else, and the four questions on this page still decide whether that placement fits its work.

### Do members install anything to reach the library?

No Artfct server and no local component is installed or kept running. Each person approves the connection once through a browser, and the same address serves every member of the workspace.

### Does one member's connection cover the rest of the team?

No. Connections are per person, and each tool asks that member to sign in. An administrator can revoke a connected tool when someone leaves the team.

### What are the size limits on a published page?

A single page is capped at 1 MB. A bundle is capped at 500 files, and in total at the lower of 8 MB and the plan ceiling: 5 MB free, 10 MB on team, 25 MB on enterprise.

### Is a library the team runs itself ever the better answer?

Yes. One person with private files, nothing shared and nobody else to serve gains nothing from a shared address. The machine the work is already on is the simpler placement for that work.
