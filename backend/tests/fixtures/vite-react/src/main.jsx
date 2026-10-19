import React from 'react';
import { createRoot } from 'react-dom/client';
import './style.css';

function App() {
  return <main data-artfct-marker="vite-react-bundle"><h1>Vite React bundle</h1><p>Served from an artfct bundle.</p></main>;
}

createRoot(document.getElementById('root')).render(<App />);
