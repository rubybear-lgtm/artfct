import { createRoot } from 'react-dom/client';

function Dashboard() {
    return <main><h1>Hydrated React revenue dashboard</h1><p>Revenue grew 40 percent.</p></main>;
}

createRoot(document.getElementById('root')!).render(<Dashboard />);
