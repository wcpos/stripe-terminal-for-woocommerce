type ButtonProps = {
  label: string; // The text displayed on the button
  onClick: () => void; // The function to call when the button is clicked
};

export const Button: React.FC<ButtonProps> = ({ label, onClick }) => (
  <button
    className="stwc-px-4 stwc-py-2 stwc-bg-blue-500 stwc-text-white stwc-rounded stwc-hover:stwc-bg-blue-600 stwc-focus:stwc-ring"
    onClick={onClick}
  >
    {label}
  </button>
);
