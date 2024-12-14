type LogDisplayProps = {
  logs: string[]; // Array of log messages
};

export const LogDisplay: React.FC<LogDisplayProps> = ({ logs }) => {
  return (
    <div className="stwc-bg-gray-100 stwc-p-4 stwc-rounded-md stwc-shadow-md stwc-h-48 stwc-overflow-y-auto stwc-border stwc-border-gray-300">
      {logs.length === 0 ? (
        <p className="stwc-text-gray-500 stwc-text-sm">No logs available.</p>
      ) : (
        logs.map((log, index) => (
          <p key={index} className="stwc-text-gray-800 stwc-text-sm stwc-mb-1">
            {log}
          </p>
        ))
      )}
    </div>
  );
};
