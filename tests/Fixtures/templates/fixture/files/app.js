async function session() {
    const response = await fetch('/__artfct/db/session', {
        headers: { Accept: 'application/json', 'X-Artfct-Db': '1' },
    });

    return response.json();
}

async function listCards(token) {
    const response = await fetch('/cards?limit=100', {
        headers: { Accept: 'application/json', Authorization: 'Bearer ' + token, 'X-Artfct-Db': '1' },
    });

    return response.json();
}

document.getElementById('board')?.append('ready');
