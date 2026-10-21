import type { ReactNode } from 'react';

/**
 * Product marks for the AI tools the library works with, so a tool is
 * recognised at a glance. Each mark is drawn at 24 units and keeps its own
 * brand colours. Codex, OpenCode, Windsurf and Antigravity come from
 * @lobehub/icons-static-svg 1.95.1 (MIT) and Zed from simple-icons (CC0);
 * one-colour marks draw in currentColor so they follow the theme.
 */
const MARKS: Record<string, ReactNode> = {
    claude: (
        <>
            <path
                d="M4.709 15.955l4.72-2.647.08-.23-.08-.128H9.2l-.79-.048-2.698-.073-2.339-.097-2.266-.122-.571-.121L0 11.784l.055-.352.48-.321.686.06 1.52.103 2.278.158 1.652.097 2.449.255h.389l.055-.157-.134-.098-.103-.097-2.358-1.596-2.552-1.688-1.336-.972-.724-.491-.364-.462-.158-1.008.656-.722.881.06.225.061.893.686 1.908 1.476 2.491 1.833.365.304.145-.103.019-.073-.164-.274-1.355-2.446-1.446-2.49-.644-1.032-.17-.619a2.97 2.97 0 01-.104-.729L6.283.134 6.696 0l.996.134.42.364.62 1.414 1.002 2.229 1.555 3.03.456.898.243.832.091.255h.158V9.01l.128-1.706.237-2.095.23-2.695.08-.76.376-.91.747-.492.584.28.48.685-.067.444-.286 1.851-.559 2.903-.364 1.942h.212l.243-.242.985-1.306 1.652-2.064.73-.82.85-.904.547-.431h1.033l.76 1.129-.34 1.166-1.064 1.347-.881 1.142-1.264 1.7-.79 1.36.073.11.188-.02 2.856-.606 1.543-.28 1.841-.315.833.388.091.395-.328.807-1.969.486-2.309.462-3.439.813-.042.03.049.061 1.549.146.662.036h1.622l3.02.225.79.522.474.638-.079.485-1.215.62-1.64-.389-3.829-.91-1.312-.329h-.182v.11l1.093 1.068 2.006 1.81 2.509 2.33.127.578-.322.455-.34-.049-2.205-1.657-.851-.747-1.926-1.62h-.128v.17l.444.649 2.345 3.521.122 1.08-.17.353-.608.213-.668-.122-1.374-1.925-1.415-2.167-1.143-1.943-.14.08-.674 7.254-.316.37-.729.28-.607-.461-.322-.747.322-1.476.389-1.924.315-1.53.286-1.9.17-.632-.012-.042-.14.018-1.434 1.967-2.18 2.945-1.726 1.845-.414.164-.717-.37.067-.662.401-.589 2.388-3.036 1.44-1.882.93-1.086-.006-.158h-.055L4.132 18.56l-1.13.146-.487-.456.061-.746.231-.243 1.908-1.312-.006.006z"
                fill="#D97757"
                fillRule="nonzero"
            />
        </>
    ),
    chatgpt: (
        <g fill="#10A37F" fillRule="evenodd">
            <path d="M21.55 10.004a5.416 5.416 0 00-.478-4.501c-1.217-2.09-3.662-3.166-6.05-2.66A5.59 5.59 0 0010.831 1C8.39.995 6.224 2.546 5.473 4.838A5.553 5.553 0 001.76 7.496a5.487 5.487 0 00.691 6.5 5.416 5.416 0 00.477 4.502c1.217 2.09 3.662 3.165 6.05 2.66A5.586 5.586 0 0013.168 23c2.443.006 4.61-1.546 5.361-3.84a5.553 5.553 0 003.715-2.66 5.488 5.488 0 00-.693-6.497v.001zm-8.381 11.558a4.199 4.199 0 01-2.675-.954c.034-.018.093-.05.132-.074l4.44-2.53a.71.71 0 00.364-.623v-6.176l1.877 1.069c.02.01.033.029.036.05v5.115c-.003 2.274-1.87 4.118-4.174 4.123zM4.192 17.78a4.059 4.059 0 01-.498-2.763c.032.02.09.055.131.078l4.44 2.53c.225.13.504.13.73 0l5.42-3.088v2.138a.068.068 0 01-.027.057L9.9 19.288c-1.999 1.136-4.552.46-5.707-1.51h-.001zM3.023 8.216A4.15 4.15 0 015.198 6.41l-.002.151v5.06a.711.711 0 00.364.624l5.42 3.087-1.876 1.07a.067.067 0 01-.063.005l-4.489-2.559c-1.995-1.14-2.679-3.658-1.53-5.63h.001zm15.417 3.54l-5.42-3.088L14.896 7.6a.067.067 0 01.063-.006l4.489 2.557c1.998 1.14 2.683 3.662 1.529 5.633a4.163 4.163 0 01-2.174 1.807V12.38a.71.71 0 00-.363-.623zm1.867-2.773a6.04 6.04 0 00-.132-.078l-4.44-2.53a.731.731 0 00-.729 0l-5.42 3.088V7.325a.068.068 0 01.027-.057L14.1 4.713c2-1.137 4.555-.46 5.707 1.513.487.833.664 1.809.499 2.757h.001zm-11.741 3.81l-1.877-1.068a.065.065 0 01-.036-.051V6.559c.001-2.277 1.873-4.122 4.181-4.12.976 0 1.92.338 2.671.954-.034.018-.092.05-.131.073l-4.44 2.53a.71.71 0 00-.365.623l-.003 6.173v.002zm1.02-2.168L12 9.25l2.414 1.375v2.75L12 14.75l-2.415-1.375v-2.75z" />
        </g>
    ),
    cursor: (
        <>
            <path
                d="M11.925 24l10.425-6-10.425-6L1.5 18l10.425 6z"
                fill="url(#aitool-cursor-fill-0)"
            />
            <path
                d="M22.35 18V6L11.925 0v12l10.425 6z"
                fill="url(#aitool-cursor-fill-1)"
            />
            <path
                d="M11.925 0L1.5 6v12l10.425-6V0z"
                fill="url(#aitool-cursor-fill-2)"
            />
            <path d="M22.35 6L11.925 24V12L22.35 6z" fill="#555" />
            <path d="M22.35 6l-10.425 6L1.5 6h20.85z" fill="#000" />
            <defs>
                <linearGradient
                    gradientUnits="userSpaceOnUse"
                    id="aitool-cursor-fill-0"
                    x1="11.925"
                    x2="11.925"
                    y1="12"
                    y2="24"
                >
                    <stop offset=".16" stopColor="#000" stopOpacity=".39" />
                    <stop offset=".658" stopColor="#000" stopOpacity=".8" />
                </linearGradient>
                <linearGradient
                    gradientUnits="userSpaceOnUse"
                    id="aitool-cursor-fill-1"
                    x1="22.35"
                    x2="11.925"
                    y1="6.037"
                    y2="12.15"
                >
                    <stop offset=".182" stopColor="#000" stopOpacity=".31" />
                    <stop offset=".715" stopColor="#000" stopOpacity="0" />
                </linearGradient>
                <linearGradient
                    gradientUnits="userSpaceOnUse"
                    id="aitool-cursor-fill-2"
                    x1="11.925"
                    x2="1.5"
                    y1="0"
                    y2="18"
                >
                    <stop stopColor="#000" stopOpacity=".6" />
                    <stop offset=".667" stopColor="#000" stopOpacity=".22" />
                </linearGradient>
            </defs>
        </>
    ),
    copilot: (
        <>
            <defs>
                <radialGradient
                    cx="85.44%"
                    cy="100.653%"
                    fx="85.44%"
                    fy="100.653%"
                    gradientTransform="scale(-.8553 -1) rotate(50.927 2.041 -1.946)"
                    id="aitool-copilot-fill-0"
                    r="105.116%"
                >
                    <stop offset="9.6%" stopColor="#00AEFF" />
                    <stop offset="77.3%" stopColor="#2253CE" />
                    <stop offset="100%" stopColor="#0736C4" />
                </radialGradient>
                <radialGradient
                    cx="18.143%"
                    cy="32.928%"
                    fx="18.143%"
                    fy="32.928%"
                    gradientTransform="scale(.8897 1) rotate(52.069 .193 .352)"
                    id="aitool-copilot-fill-1"
                    r="95.612%"
                >
                    <stop offset="0%" stopColor="#FFB657" />
                    <stop offset="63.4%" stopColor="#FF5F3D" />
                    <stop offset="92.3%" stopColor="#C02B3C" />
                </radialGradient>
                <radialGradient
                    cx="82.987%"
                    cy="-9.792%"
                    fx="82.987%"
                    fy="-9.792%"
                    gradientTransform="scale(-1 -.9441) rotate(-70.872 .142 1.17)"
                    id="aitool-copilot-fill-4"
                    r="140.622%"
                >
                    <stop offset="6.6%" stopColor="#8C48FF" />
                    <stop offset="50%" stopColor="#F2598A" />
                    <stop offset="89.6%" stopColor="#FFB152" />
                </radialGradient>
                <linearGradient
                    id="aitool-copilot-fill-2"
                    x1="39.465%"
                    x2="46.884%"
                    y1="12.117%"
                    y2="103.774%"
                >
                    <stop offset="15.6%" stopColor="#0D91E1" />
                    <stop offset="48.7%" stopColor="#52B471" />
                    <stop offset="65.2%" stopColor="#98BD42" />
                    <stop offset="93.7%" stopColor="#FFC800" />
                </linearGradient>
                <linearGradient
                    id="aitool-copilot-fill-3"
                    x1="45.949%"
                    x2="50%"
                    y1="0%"
                    y2="100%"
                >
                    <stop offset="0%" stopColor="#3DCBFF" />
                    <stop offset="24.7%" stopColor="#0588F7" stopOpacity="0" />
                </linearGradient>
                <linearGradient
                    id="aitool-copilot-fill-5"
                    x1="83.507%"
                    x2="83.453%"
                    y1="-6.106%"
                    y2="21.131%"
                >
                    <stop offset="5.8%" stopColor="#F8ADFA" />
                    <stop offset="70.8%" stopColor="#A86EDD" stopOpacity="0" />
                </linearGradient>
            </defs>
            <g fill="none" fillRule="nonzero">
                <path
                    d="M17.533 1.829A2.528 2.528 0 0015.11 0h-.737a2.531 2.531 0 00-2.484 2.087l-1.263 6.937.314-1.08a2.528 2.528 0 012.424-1.833h4.284l1.797.706 1.731-.706h-.505a2.528 2.528 0 01-2.423-1.829l-.715-2.453z"
                    fill="url(#aitool-copilot-fill-0)"
                    transform="translate(0 1)"
                />
                <path
                    d="M6.726 20.16A2.528 2.528 0 009.152 22h1.566c1.37 0 2.49-1.1 2.525-2.48l.17-6.69-.357 1.228a2.528 2.528 0 01-2.423 1.83h-4.32l-1.54-.842-1.667.843h.497c1.124 0 2.113.75 2.426 1.84l.697 2.432z"
                    fill="url(#aitool-copilot-fill-1)"
                    transform="translate(0 1)"
                />
                <path
                    d="M15 0H6.252c-2.5 0-4 3.331-5 6.662-1.184 3.947-2.734 9.225 1.75 9.225H6.78c1.13 0 2.12-.753 2.43-1.847.657-2.317 1.809-6.359 2.713-9.436.46-1.563.842-2.906 1.43-3.742A1.97 1.97 0 0115 0"
                    fill="url(#aitool-copilot-fill-2)"
                    transform="translate(0 1)"
                />
                <path
                    d="M15 0H6.252c-2.5 0-4 3.331-5 6.662-1.184 3.947-2.734 9.225 1.75 9.225H6.78c1.13 0 2.12-.753 2.43-1.847.657-2.317 1.809-6.359 2.713-9.436.46-1.563.842-2.906 1.43-3.742A1.97 1.97 0 0115 0"
                    fill="url(#aitool-copilot-fill-3)"
                    transform="translate(0 1)"
                />
                <path
                    d="M9 22h8.749c2.5 0 4-3.332 5-6.663 1.184-3.948 2.734-9.227-1.75-9.227H17.22c-1.129 0-2.12.754-2.43 1.848a1149.2 1149.2 0 01-2.713 9.437c-.46 1.564-.842 2.907-1.43 3.743A1.97 1.97 0 019 22"
                    fill="url(#aitool-copilot-fill-4)"
                    transform="translate(0 1)"
                />
                <path
                    d="M9 22h8.749c2.5 0 4-3.332 5-6.663 1.184-3.948 2.734-9.227-1.75-9.227H17.22c-1.129 0-2.12.754-2.43 1.848a1149.2 1149.2 0 01-2.713 9.437c-.46 1.564-.842 2.907-1.43 3.743A1.97 1.97 0 019 22"
                    fill="url(#aitool-copilot-fill-5)"
                    transform="translate(0 1)"
                />
            </g>
        </>
    ),
    codex: (
        <>
            <path
                d="M19.503 0H4.496A4.496 4.496 0 000 4.496v15.007A4.496 4.496 0 004.496 24h15.007A4.496 4.496 0 0024 19.503V4.496A4.496 4.496 0 0019.503 0z"
                fill="#fff"
            />
            <path
                d="M9.064 3.344a4.578 4.578 0 012.285-.312c1 .115 1.891.54 2.673 1.275.01.01.024.017.037.021a.09.09 0 00.043 0 4.55 4.55 0 013.046.275l.047.022.116.057a4.581 4.581 0 012.188 2.399c.209.51.313 1.041.315 1.595a4.24 4.24 0 01-.134 1.223.123.123 0 00.03.115c.594.607.988 1.33 1.183 2.17.289 1.425-.007 2.71-.887 3.854l-.136.166a4.548 4.548 0 01-2.201 1.388.123.123 0 00-.081.076c-.191.551-.383 1.023-.74 1.494-.9 1.187-2.222 1.846-3.711 1.838-1.187-.006-2.239-.44-3.157-1.302a.107.107 0 00-.105-.024c-.388.125-.78.143-1.204.138a4.441 4.441 0 01-1.945-.466 4.544 4.544 0 01-1.61-1.335c-.152-.202-.303-.392-.414-.617a5.81 5.81 0 01-.37-.961 4.582 4.582 0 01-.014-2.298.124.124 0 00.006-.056.085.085 0 00-.027-.048 4.467 4.467 0 01-1.034-1.651 3.896 3.896 0 01-.251-1.192 5.189 5.189 0 01.141-1.6c.337-1.112.982-1.985 1.933-2.618.212-.141.413-.251.601-.33.215-.089.43-.164.646-.227a.098.098 0 00.065-.066 4.51 4.51 0 01.829-1.615 4.535 4.535 0 011.837-1.388zm3.482 10.565a.637.637 0 000 1.272h3.636a.637.637 0 100-1.272h-3.636zM8.462 9.23a.637.637 0 00-1.106.631l1.272 2.224-1.266 2.136a.636.636 0 101.095.649l1.454-2.455a.636.636 0 00.005-.64L8.462 9.23z"
                fill="url(#aitool-codex-0)"
            />
            <defs>
                <linearGradient
                    gradientUnits="userSpaceOnUse"
                    id="aitool-codex-0"
                    x1="12"
                    x2="12"
                    y1="3"
                    y2="21"
                >
                    <stop stopColor="#B1A7FF" />
                    <stop offset=".5" stopColor="#7A9DFF" />
                    <stop offset="1" stopColor="#3941FF" />
                </linearGradient>
            </defs>
        </>
    ),
    opencode: (
        <>
            <g fill="currentColor" fillRule="evenodd">
                <path d="M16 6H8v12h8V6zm4 16H4V2h16v20z" />
            </g>
        </>
    ),
    windsurf: (
        <>
            <g fill="currentColor" fillRule="evenodd">
                <path
                    clipRule="evenodd"
                    d="M23.78 5.004h-.228a2.187 2.187 0 00-2.18 2.196v4.912c0 .98-.804 1.775-1.76 1.775a1.818 1.818 0 01-1.472-.773L13.168 5.95a2.197 2.197 0 00-1.81-.95c-1.134 0-2.154.972-2.154 2.173v4.94c0 .98-.797 1.775-1.76 1.775-.57 0-1.136-.289-1.472-.773L.408 5.098C.282 4.918 0 5.007 0 5.228v4.284c0 .216.066.426.188.604l5.475 7.889c.324.466.8.812 1.351.938 1.377.316 2.645-.754 2.645-2.117V11.89c0-.98.787-1.775 1.76-1.775h.002c.586 0 1.135.288 1.472.773l4.972 7.163a2.15 2.15 0 001.81.95c1.158 0 2.151-.973 2.151-2.173v-4.939c0-.98.787-1.775 1.76-1.775h.194c.122 0 .22-.1.22-.222V5.225a.221.221 0 00-.22-.222z"
                />
            </g>
        </>
    ),
    zed: (
        <>
            <g fill="currentColor" fillRule="evenodd">
                <path d="M2.25 1.5a.75.75 0 0 0-.75.75v16.5H0V2.25A2.25 2.25 0 0 1 2.25 0h20.095c1.002 0 1.504 1.212.795 1.92L10.764 14.298h3.486V12.75h1.5v1.922a1.125 1.125 0 0 1-1.125 1.125H9.264l-2.578 2.578h11.689V9h1.5v9.375a1.5 1.5 0 0 1-1.5 1.5H5.185L2.562 22.5H21.75a.75.75 0 0 0 .75-.75V5.25H24v16.5A2.25 2.25 0 0 1 21.75 24H1.655C.653 24 .151 22.788.86 22.08L13.19 9.75H9.75v1.5h-1.5V9.375A1.125 1.125 0 0 1 9.375 8.25h5.314l2.625-2.625H5.625V15h-1.5V5.625a1.5 1.5 0 0 1 1.5-1.5h13.19L21.438 1.5z" />
            </g>
        </>
    ),
    antigravity: (
        <>
            <mask
                height="23"
                id="aitool-antigravity-0"
                maskUnits="userSpaceOnUse"
                width="24"
                x="0"
                y="1"
            >
                <path
                    d="M21.751 22.607c1.34 1.005 3.35.335 1.508-1.508C17.73 15.74 18.904 1 12.037 1 5.17 1 6.342 15.74.815 21.1c-2.01 2.009.167 2.511 1.507 1.506 5.192-3.517 4.857-9.714 9.715-9.714 4.857 0 4.522 6.197 9.714 9.715z"
                    fill="#fff"
                />
            </mask>
            <g mask="url(#aitool-antigravity-0)">
                <g filter="url(#aitool-antigravity-1)">
                    <path
                        d="M-1.018-3.992c-.408 3.591 2.686 6.89 6.91 7.37 4.225.48 7.98-2.043 8.387-5.633.408-3.59-2.686-6.89-6.91-7.37-4.225-.479-7.98 2.043-8.387 5.633z"
                        fill="#FFE432"
                    />
                </g>
                <g filter="url(#aitool-antigravity-2)">
                    <path
                        d="M15.269 7.747c1.058 4.557 5.691 7.374 10.348 6.293 4.657-1.082 7.575-5.653 6.516-10.21-1.058-4.556-5.691-7.374-10.348-6.292-4.657 1.082-7.575 5.653-6.516 10.21z"
                        fill="#FC413D"
                    />
                </g>
                <g filter="url(#aitool-antigravity-3)">
                    <path
                        d="M-12.443 10.804c1.338 4.703 7.36 7.11 13.453 5.378 6.092-1.733 9.947-6.95 8.61-11.652C8.282-.173 2.26-2.58-3.833-.848-9.925.884-13.78 6.1-12.443 10.804z"
                        fill="#00B95C"
                    />
                </g>
                <g filter="url(#aitool-antigravity-4)">
                    <path
                        d="M-12.443 10.804c1.338 4.703 7.36 7.11 13.453 5.378 6.092-1.733 9.947-6.95 8.61-11.652C8.282-.173 2.26-2.58-3.833-.848-9.925.884-13.78 6.1-12.443 10.804z"
                        fill="#00B95C"
                    />
                </g>
                <g filter="url(#aitool-antigravity-5)">
                    <path
                        d="M-7.608 14.703c3.352 3.424 9.126 3.208 12.896-.483 3.77-3.69 4.108-9.459.756-12.883C2.69-2.087-3.083-1.871-6.853 1.82c-3.77 3.69-4.108 9.458-.755 12.883z"
                        fill="#00B95C"
                    />
                </g>
                <g filter="url(#aitool-antigravity-6)">
                    <path
                        d="M9.932 27.617c1.04 4.482 5.384 7.303 9.7 6.3 4.316-1.002 6.971-5.448 5.93-9.93-1.04-4.483-5.384-7.304-9.7-6.301-4.316 1.002-6.971 5.448-5.93 9.93z"
                        fill="#3186FF"
                    />
                </g>
                <g filter="url(#aitool-antigravity-7)">
                    <path
                        d="M2.572-8.185C.392-3.329 2.778 2.472 7.9 4.771c5.122 2.3 11.042.227 13.222-4.63 2.18-4.855-.205-10.656-5.327-12.955-5.122-2.3-11.042-.227-13.222 4.63z"
                        fill="#FBBC04"
                    />
                </g>
                <g filter="url(#aitool-antigravity-8)">
                    <path
                        d="M-3.267 38.686c-5.277-2.072 3.742-19.117 5.984-24.83 2.243-5.712 8.34-8.664 13.616-6.592 5.278 2.071 11.533 13.482 9.29 19.195-2.242 5.713-23.613 14.298-28.89 12.227z"
                        fill="#3186FF"
                    />
                </g>
                <g filter="url(#aitool-antigravity-9)">
                    <path
                        d="M28.71 17.471c-1.413 1.649-5.1.808-8.236-1.878-3.135-2.687-4.531-6.201-3.118-7.85 1.412-1.649 5.1-.808 8.235 1.878s4.532 6.2 3.119 7.85z"
                        fill="#749BFF"
                    />
                </g>
                <g filter="url(#aitool-antigravity-10)">
                    <path
                        d="M18.163 9.077c5.81 3.93 12.502 4.19 14.946.577 2.443-3.612-.287-9.727-6.098-13.658-5.81-3.931-12.502-4.19-14.946-.577-2.443 3.612.287 9.727 6.098 13.658z"
                        fill="#FC413D"
                    />
                </g>
                <g filter="url(#aitool-antigravity-11)">
                    <path
                        d="M-.915 2.684c-1.44 3.473-.97 6.967 1.05 7.804 2.02.837 4.824-1.3 6.264-4.772 1.44-3.473.97-6.967-1.05-7.804-2.02-.837-4.824 1.3-6.264 4.772z"
                        fill="#FFEE48"
                    />
                </g>
            </g>
            <defs>
                <filter
                    colorInterpolationFilters="sRGB"
                    filterUnits="userSpaceOnUse"
                    height="17.587"
                    id="aitool-antigravity-1"
                    width="19.838"
                    x="-3.288"
                    y="-11.917"
                >
                    <feFlood floodOpacity="0" result="BackgroundImageFix" />
                    <feBlend
                        in="SourceGraphic"
                        in2="BackgroundImageFix"
                        result="shape"
                    />
                    <feGaussianBlur
                        result="effect1_foregroundBlur_977_115"
                        stdDeviation="1.117"
                    />
                </filter>
                <filter
                    colorInterpolationFilters="sRGB"
                    filterUnits="userSpaceOnUse"
                    height="38.565"
                    id="aitool-antigravity-2"
                    width="38.9"
                    x="4.251"
                    y="-13.493"
                >
                    <feFlood floodOpacity="0" result="BackgroundImageFix" />
                    <feBlend
                        in="SourceGraphic"
                        in2="BackgroundImageFix"
                        result="shape"
                    />
                    <feGaussianBlur
                        result="effect1_foregroundBlur_977_115"
                        stdDeviation="5.4"
                    />
                </filter>
                <filter
                    colorInterpolationFilters="sRGB"
                    filterUnits="userSpaceOnUse"
                    height="36.517"
                    id="aitool-antigravity-3"
                    width="40.955"
                    x="-21.889"
                    y="-10.592"
                >
                    <feFlood floodOpacity="0" result="BackgroundImageFix" />
                    <feBlend
                        in="SourceGraphic"
                        in2="BackgroundImageFix"
                        result="shape"
                    />
                    <feGaussianBlur
                        result="effect1_foregroundBlur_977_115"
                        stdDeviation="4.591"
                    />
                </filter>
                <filter
                    colorInterpolationFilters="sRGB"
                    filterUnits="userSpaceOnUse"
                    height="36.517"
                    id="aitool-antigravity-4"
                    width="40.955"
                    x="-21.889"
                    y="-10.592"
                >
                    <feFlood floodOpacity="0" result="BackgroundImageFix" />
                    <feBlend
                        in="SourceGraphic"
                        in2="BackgroundImageFix"
                        result="shape"
                    />
                    <feGaussianBlur
                        result="effect1_foregroundBlur_977_115"
                        stdDeviation="4.591"
                    />
                </filter>
                <filter
                    colorInterpolationFilters="sRGB"
                    filterUnits="userSpaceOnUse"
                    height="36.595"
                    id="aitool-antigravity-5"
                    width="36.632"
                    x="-19.099"
                    y="-10.278"
                >
                    <feFlood floodOpacity="0" result="BackgroundImageFix" />
                    <feBlend
                        in="SourceGraphic"
                        in2="BackgroundImageFix"
                        result="shape"
                    />
                    <feGaussianBlur
                        result="effect1_foregroundBlur_977_115"
                        stdDeviation="4.591"
                    />
                </filter>
                <filter
                    colorInterpolationFilters="sRGB"
                    filterUnits="userSpaceOnUse"
                    height="34.087"
                    id="aitool-antigravity-6"
                    width="33.533"
                    x=".981"
                    y="8.758"
                >
                    <feFlood floodOpacity="0" result="BackgroundImageFix" />
                    <feBlend
                        in="SourceGraphic"
                        in2="BackgroundImageFix"
                        result="shape"
                    />
                    <feGaussianBlur
                        result="effect1_foregroundBlur_977_115"
                        stdDeviation="4.363"
                    />
                </filter>
                <filter
                    colorInterpolationFilters="sRGB"
                    filterUnits="userSpaceOnUse"
                    height="35.276"
                    id="aitool-antigravity-7"
                    width="35.978"
                    x="-6.143"
                    y="-21.659"
                >
                    <feFlood floodOpacity="0" result="BackgroundImageFix" />
                    <feBlend
                        in="SourceGraphic"
                        in2="BackgroundImageFix"
                        result="shape"
                    />
                    <feGaussianBlur
                        result="effect1_foregroundBlur_977_115"
                        stdDeviation="3.954"
                    />
                </filter>
                <filter
                    colorInterpolationFilters="sRGB"
                    filterUnits="userSpaceOnUse"
                    height="46.523"
                    id="aitool-antigravity-8"
                    width="45.114"
                    x="-11.96"
                    y="-.46"
                >
                    <feFlood floodOpacity="0" result="BackgroundImageFix" />
                    <feBlend
                        in="SourceGraphic"
                        in2="BackgroundImageFix"
                        result="shape"
                    />
                    <feGaussianBlur
                        result="effect1_foregroundBlur_977_115"
                        stdDeviation="3.531"
                    />
                </filter>
                <filter
                    colorInterpolationFilters="sRGB"
                    filterUnits="userSpaceOnUse"
                    height="24.054"
                    id="aitool-antigravity-9"
                    width="25.094"
                    x="10.485"
                    y=".58"
                >
                    <feFlood floodOpacity="0" result="BackgroundImageFix" />
                    <feBlend
                        in="SourceGraphic"
                        in2="BackgroundImageFix"
                        result="shape"
                    />
                    <feGaussianBlur
                        result="effect1_foregroundBlur_977_115"
                        stdDeviation="3.159"
                    />
                </filter>
                <filter
                    colorInterpolationFilters="sRGB"
                    filterUnits="userSpaceOnUse"
                    height="30.007"
                    id="aitool-antigravity-10"
                    width="33.508"
                    x="5.833"
                    y="-12.467"
                >
                    <feFlood floodOpacity="0" result="BackgroundImageFix" />
                    <feBlend
                        in="SourceGraphic"
                        in2="BackgroundImageFix"
                        result="shape"
                    />
                    <feGaussianBlur
                        result="effect1_foregroundBlur_977_115"
                        stdDeviation="2.669"
                    />
                </filter>
                <filter
                    colorInterpolationFilters="sRGB"
                    filterUnits="userSpaceOnUse"
                    height="26.151"
                    id="aitool-antigravity-11"
                    width="22.194"
                    x="-8.355"
                    y="-8.876"
                >
                    <feFlood floodOpacity="0" result="BackgroundImageFix" />
                    <feBlend
                        in="SourceGraphic"
                        in2="BackgroundImageFix"
                        result="shape"
                    />
                    <feGaussianBlur
                        result="effect1_foregroundBlur_977_115"
                        stdDeviation="3.303"
                    />
                </filter>
            </defs>
        </>
    ),
};

/** Tool ids that share another tool's mark. */
const MARK_ALIASES: Record<string, string> = {
    'claude-code': 'claude',
};

export function aiToolMarkKey(id: string | null | undefined): string | null {
    if (!id) {
        return null;
    }

    const key = MARK_ALIASES[id] ?? id;

    return key in MARKS ? key : null;
}

type AiToolIconProps = {
    tool: string | null | undefined;
    size?: number;
    className?: string;
};

/** The mark for a tool id, or nothing when the tool has no mark yet. */
export function AiToolIcon({ tool, size = 20, className }: AiToolIconProps) {
    const key = aiToolMarkKey(tool);

    if (!key) {
        return null;
    }

    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 24 24"
            className={className}
            aria-hidden="true"
            focusable="false"
        >
            {MARKS[key]}
        </svg>
    );
}
