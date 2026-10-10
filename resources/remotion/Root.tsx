import { Composition } from 'remotion';

import {
    defaultLandingFlowProps,
    LandingFlowMobile,
} from './LandingFlowMobile';
import {
    defaultLandingFlowWideProps,
    LandingFlowWide,
    WIDE_FRAMES,
} from './LandingFlowWide';
import { DURATION_IN_FRAMES, FPS, HEIGHT, WIDTH } from './theme';

export function RemotionRoot() {
    return (
        <>
            <Composition
                id="LandingFlowWide"
                component={LandingFlowWide}
                durationInFrames={WIDE_FRAMES}
                fps={FPS}
                width={1600}
                height={720}
                defaultProps={defaultLandingFlowWideProps}
            />
            <Composition
                id="LandingFlowMobile"
                component={LandingFlowMobile}
                durationInFrames={DURATION_IN_FRAMES}
                fps={FPS}
                width={WIDTH}
                height={HEIGHT}
                defaultProps={defaultLandingFlowProps}
            />
        </>
    );
}
