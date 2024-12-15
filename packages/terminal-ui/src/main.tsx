import ReactDOM from 'react-dom/client';
import { App } from './App';
import './index.css';

const container = document.getElementById('stripe-terminal-app');
if (container) {
  const root = ReactDOM.createRoot(container);
  root.render(<App />);
}
