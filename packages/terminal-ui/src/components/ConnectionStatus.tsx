type ConnectionStatusProps = {
  status: 'connected' | 'connecting' | 'disconnected'; // Define possible statuses
};

export const ConnectionStatus: React.FC<ConnectionStatusProps> = ({ status }) => {
  const statusColors = {
    connected: 'stwc-bg-green-500 stwc-text-white',
    connecting: 'stwc-bg-yellow-500 stwc-text-white',
    disconnected: 'stwc-bg-red-500 stwc-text-white',
  };

  const statusText = {
    connected: 'Connected',
    connecting: 'Connecting...',
    disconnected: 'Disconnected',
  };

  return (
    <div
      className={`stwc-p-3 stwc-rounded-md stwc-text-center stwc-font-medium stwc-shadow-md ${statusColors[status]}`}
    >
      {statusText[status]}
    </div>
  );
};
