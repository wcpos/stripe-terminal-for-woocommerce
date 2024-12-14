import React from 'react';
import { Button } from './components/Button';
import { ConnectionStatus } from './components/ConnectionStatus';
import { LogDisplay } from './components/LogDisplay';

const App = () => {
  const [status, setStatus] = React.useState<'connected' | 'connecting' | 'disconnected'>('disconnected'); // Explicitly typed
  const [logs, setLogs] = React.useState<string[]>([]);

  const log = (message: string) => setLogs((prev) => [...prev, message]);

  const handleDiscoverReaders = async () => {
    log('Discovering readers...');
    // Simulate reader discovery
    setTimeout(() => {
      log('Reader discovered: Stripe Reader X123');
      setStatus('connected');
    }, 1000);
  };

  return (
    <div className="p-4">
      <ConnectionStatus status={status} />
      <div className="mt-4">
        <Button label="Discover Readers" onClick={handleDiscoverReaders} />
      </div>
      <LogDisplay logs={logs} />
    </div>
  );
};

export default App;
