import { loadFont } from '@remotion/fonts';
import { useEffect } from 'react';
import {
    AbsoluteFill,
    Audio,
    Easing,
    interpolate,
    staticFile,
    useCurrentFrame,
} from 'remotion';

import { Row, Stack } from './icons';
import {
    AppChip,
    ArtifactCard,
    Caption,
    Connector,
    LibraryRow,
    Panel,
    PanelFace,
    Person,
    Pill,
    PlaceholderSlot,
    ShareFooter,
    StepDots,
    ToolRow,
    Well,
} from './parts';
import { fontSans, token, u } from './theme';
import { beat, caption, CONTENT, layout, step, width } from './timeline';

export type FontAsset = {
    family: string;
    weight: string;
    style?: string;
    url: string;
};

export type LandingFlowProps = {
    fonts: FontAsset[];
    audio: string | null;
};

export const defaultLandingFlowProps: LandingFlowProps = {
    fonts: [],
    audio: null,
};

const lerp = (from: number, to: number, progress: number): number =>
    from + (to - from) * progress;

export function LandingFlowMobile({ fonts, audio }: LandingFlowProps) {
    const frame = useCurrentFrame();

    useEffect(() => {
        for (const font of fonts) {
            void loadFont({
                family: font.family,
                url: staticFile(font.url),
                weight: font.weight,
                style: font.style ?? 'normal',
            });
        }
    }, [fonts]);

    /** A one-way ease, used for every entrance in the piece. */
    const ramp = (startFrame: number, duration = 12): number =>
        interpolate(frame, [startFrame, startFrame + duration], [0, 1], {
            extrapolateLeft: 'clamp',
            extrapolateRight: 'clamp',
            easing: Easing.bezier(0.33, 0, 0.2, 1),
        });

    const libraryIn = ramp(beat.libraryIn, 14);
    const cursorIn = ramp(beat.cursorIn, 14);

    const claudeHeight = lerp(layout.claude, layout.compact, libraryIn);
    const libraryHeight =
        libraryIn * lerp(layout.library, layout.compact, cursorIn);
    const cursorHeight = cursorIn * layout.cursor;

    const libraryGap = layout.gap * libraryIn;
    const libraryConnector = layout.connector * libraryIn;
    const cursorGap = layout.gap * cursorIn;
    const cursorConnector = layout.connector * cursorIn;

    const libraryTop =
        claudeHeight + libraryGap + libraryConnector + libraryGap;
    const cursorTop =
        libraryTop + libraryHeight + cursorGap + cursorConnector + cursorGap;

    const typed = CONTENT.prompt.slice(
        0,
        Math.round(
            interpolate(
                frame,
                [beat.typingStart, beat.typingEnd],
                [0, CONTENT.prompt.length],
                { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' },
            ),
        ),
    );

    const artifactIn = ramp(beat.artifactIn, 10);
    const shareRowIn = ramp(beat.shareRowIn, 10);
    const shared = frame >= beat.libraryIn;
    const placeholderIn =
        ramp(beat.placeholderIn, 8) * (1 - ramp(beat.libraryRowIn, 8));
    const libraryRowIn = ramp(beat.libraryRowIn, 10);
    const ready = frame >= beat.readyIn;
    const toolsLabelIn = ramp(beat.toolsLabelIn, 10);
    const toolReveals = [0, 1, 2, 3].map((index) =>
        ramp(beat.toolIn + index * beat.toolStagger, 12),
    );
    const libraryPulse = interpolate(
        frame,
        [beat.libraryPulse, beat.libraryPulse + 8, beat.libraryPulse + 34],
        [0, 1, 0],
        { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' },
    );

    const cursorWellIn = ramp(beat.cursorWellIn, 8);
    const foundLineIn = ramp(beat.foundLineIn, 8);
    const sourceCardIn = ramp(beat.sourceCardIn, 10);
    const sourcePillIn = ramp(beat.sourcePillIn, 10);
    const answer = CONTENT.answer.slice(
        0,
        Math.max(
            0,
            Math.floor((frame - beat.answerIn) / beat.answerFramesPerCharacter),
        ),
    );

    const stepIndex =
        frame >= step.answers
            ? 3
            : frame >= step.findable
              ? 2
              : frame >= step.shares
                ? 1
                : 0;

    const dotFill = [step.asks, step.shares, step.findable, step.answers].map(
        (start) => ramp(start, 10),
    );

    const fadeIn = interpolate(frame, [0, 10], [1, 0], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
    });
    const fadeOut = interpolate(
        frame,
        [beat.fadeOut, beat.fadeOut + 15],
        [0, 1],
        { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' },
    );

    return (
        <AbsoluteFill
            style={{
                backgroundColor: token.bone,
                color: token.ink,
                fontFamily: fontSans,
                WebkitFontSmoothing: 'antialiased',
            }}
        >
            <AbsoluteFill style={{ paddingInline: u(layout.padInline) }}>
                <div
                    style={{
                        position: 'absolute',
                        top: u(layout.top),
                        left: u(layout.padInline),
                        right: u(layout.padInline),
                    }}
                >
                    <Panel
                        top={0}
                        height={claudeHeight}
                        enter={1}
                        dim={lerp(1, 0.72, libraryIn)}
                    >
                        <PanelFace mode="expanded" visible={1 - libraryIn}>
                            <Stack gap={9}>
                                <Row gap={8}>
                                    <AppChip label="Claude" glyph="star" />
                                    <Person name={CONTENT.editor} />
                                </Row>
                                <Well
                                    text={typed}
                                    minHeight={44}
                                    caret={frame < beat.typingEnd + 8}
                                />
                                <ArtifactCard
                                    title={CONTENT.artifact}
                                    meta={CONTENT.madeIn}
                                    style={{
                                        opacity: artifactIn,
                                        transform: `translateY(${u(10) * (1 - artifactIn)}px)`,
                                    }}
                                />
                                <div style={{ opacity: shareRowIn }}>
                                    <ShareFooter shared={shared} />
                                </div>
                            </Stack>
                        </PanelFace>
                        <PanelFace mode="compact" visible={libraryIn}>
                            <Row gap={8}>
                                <AppChip label="Claude" glyph="star" />
                                <span
                                    style={{
                                        fontSize: u(11),
                                        fontWeight: 600,
                                        color: token.ink,
                                    }}
                                >
                                    {CONTENT.artifact}
                                </span>
                                <span style={{ flex: 1 }} />
                                <Pill
                                    label={shared ? 'Shared' : 'Share'}
                                    tone={shared ? 'ok' : 'ox'}
                                />
                            </Row>
                        </PanelFace>
                    </Panel>

                    <div style={{ position: 'absolute', left: 0, right: 0 }}>
                        <div
                            style={{
                                position: 'absolute',
                                top: u(claudeHeight + libraryGap),
                                left: 0,
                                right: 0,
                            }}
                        >
                            <Connector label="Share" revealed={libraryIn} />
                        </div>
                        <div
                            style={{
                                position: 'absolute',
                                top: u(libraryTop + libraryHeight + cursorGap),
                                left: 0,
                                right: 0,
                            }}
                        >
                            <Connector
                                label="Any AI tool can find it"
                                revealed={cursorIn}
                                highlighted={frame >= beat.libraryPulse}
                            />
                        </div>
                    </div>

                    <Panel
                        top={libraryTop}
                        height={libraryHeight}
                        enter={libraryIn}
                        dim={lerp(1, 0.72, cursorIn)}
                    >
                        <PanelFace mode="expanded" visible={1 - cursorIn}>
                            <Stack gap={7}>
                                <span
                                    style={{
                                        fontSize: u(11.5),
                                        fontWeight: 600,
                                        color: token.ink,
                                        lineHeight: 1.2,
                                    }}
                                >
                                    {CONTENT.libraryTitle}
                                </span>
                                <div style={{ position: 'relative' }}>
                                    <div
                                        style={{
                                            position: 'absolute',
                                            inset: 0,
                                            opacity: placeholderIn,
                                        }}
                                    >
                                        <PlaceholderSlot />
                                    </div>
                                    <div style={{ opacity: libraryRowIn }}>
                                        <LibraryRow
                                            glyph="star"
                                            title={CONTENT.artifact}
                                            meta={`Claude · ${CONTENT.editor}`}
                                            highlighted
                                            pulse={libraryPulse}
                                            badge={{
                                                label: ready
                                                    ? CONTENT.ready
                                                    : CONTENT.reading,
                                                ready,
                                            }}
                                            style={{
                                                transform: `translateY(${u(8) * (1 - libraryRowIn)}px)`,
                                            }}
                                        />
                                    </div>
                                </div>
                                {CONTENT.neighbour.map((item, index) => (
                                    <LibraryRow
                                        key={item.title}
                                        glyph={
                                            item.glyph === 'chat'
                                                ? 'chat'
                                                : 'pointer'
                                        }
                                        title={item.title}
                                        meta={item.meta}
                                        style={{
                                            opacity: interpolate(
                                                frame,
                                                [
                                                    beat.placeholderIn +
                                                        index * 4,
                                                    beat.placeholderIn +
                                                        8 +
                                                        index * 4,
                                                ],
                                                [0, 1],
                                                {
                                                    extrapolateLeft: 'clamp',
                                                    extrapolateRight: 'clamp',
                                                },
                                            ),
                                        }}
                                    />
                                ))}
                                <div
                                    style={{
                                        opacity: toolsLabelIn,
                                        marginTop: u(2),
                                    }}
                                >
                                    <span
                                        style={{
                                            fontSize: u(9),
                                            fontWeight: 600,
                                            color: token.ink3,
                                            lineHeight: 1.2,
                                        }}
                                    >
                                        {CONTENT.libraryToolLabel}
                                    </span>
                                </div>
                                <ToolRow reveals={toolReveals} />
                            </Stack>
                        </PanelFace>
                        <PanelFace mode="compact" visible={cursorIn}>
                            <Row gap={8}>
                                <span
                                    style={{
                                        fontSize: u(11.5),
                                        fontWeight: 600,
                                        color: token.ink,
                                    }}
                                >
                                    {CONTENT.libraryTitle}
                                </span>
                                <span style={{ flex: 1 }} />
                                <span
                                    style={{
                                        display: 'inline-flex',
                                        alignItems: 'center',
                                        gap: u(3),
                                        fontSize: u(9),
                                        fontWeight: 600,
                                        color: token.ink2,
                                    }}
                                >
                                    {CONTENT.artifact} · {CONTENT.ready}
                                </span>
                            </Row>
                        </PanelFace>
                    </Panel>

                    <Panel
                        top={cursorTop}
                        height={cursorHeight}
                        enter={cursorIn}
                        dim={1}
                    >
                        <PanelFace mode="expanded" visible={1}>
                            <Stack gap={7}>
                                <Row gap={8}>
                                    <AppChip
                                        label="Cursor"
                                        glyph="pointer"
                                        tone="ox"
                                    />
                                    <Person
                                        name={CONTENT.reader}
                                        right={
                                            <Pill label="New chat" tone="ox" />
                                        }
                                    />
                                </Row>
                                <div style={{ opacity: cursorWellIn }}>
                                    <Well
                                        text={CONTENT.readerPrompt}
                                        minHeight={30}
                                    />
                                </div>
                                <span
                                    style={{
                                        fontSize: u(9.5),
                                        color: token.ink3,
                                        lineHeight: 1.3,
                                        opacity: foundLineIn,
                                    }}
                                >
                                    {CONTENT.found}
                                </span>
                                <ArtifactCard
                                    title={CONTENT.artifact}
                                    meta={`Claude · ${CONTENT.editor}`}
                                    style={{
                                        opacity: sourceCardIn,
                                        transform: `translateY(${u(10) * (1 - sourceCardIn)}px)`,
                                    }}
                                />
                                <span
                                    style={{
                                        fontSize: u(12.5),
                                        lineHeight: 1.4,
                                        color: token.ink,
                                        minHeight: u(36),
                                    }}
                                >
                                    {answer}
                                </span>
                                <div
                                    style={{
                                        opacity: sourcePillIn,
                                        transform: `translateY(${u(8) * (1 - sourcePillIn)}px)`,
                                    }}
                                >
                                    <Pill label={CONTENT.source} tone="ox" />
                                </div>
                            </Stack>
                        </PanelFace>
                    </Panel>
                </div>

                <div
                    style={{
                        position: 'absolute',
                        top: u(
                            layout.top +
                                layout.claude +
                                2 * (layout.gap + layout.connector) +
                                layout.library +
                                layout.dotsGapTop,
                        ),
                        left: 0,
                        right: 0,
                    }}
                >
                    <StepDots frame={frame} fill={dotFill} />
                </div>

                <div
                    style={{
                        position: 'absolute',
                        top: u(
                            layout.top +
                                layout.claude +
                                2 * (layout.gap + layout.connector) +
                                layout.library +
                                layout.dotsGapTop +
                                layout.dots +
                                layout.captionGap,
                        ),
                        left: 0,
                        right: 0,
                        height: u(40),
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                    }}
                >
                    <Caption text={caption[stepIndex]} />
                </div>
            </AbsoluteFill>

            {audio ? <Audio src={staticFile(audio)} /> : null}

            <AbsoluteFill
                style={{
                    backgroundColor: token.bone,
                    opacity: Math.max(fadeIn, fadeOut),
                    pointerEvents: 'none',
                }}
            />

            <AbsoluteFill style={{ width: u(width) }} />
        </AbsoluteFill>
    );
}
