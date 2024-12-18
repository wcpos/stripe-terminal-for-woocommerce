import React, { ChangeEvent } from 'react';

interface SelectItem {
	value: string;
	label: string;
}

interface SelectProps {
	value: string;
	items: SelectItem[];
	onChange: (value: string) => void;
	required?: boolean;
}

export const Select = ({ value, items, onChange, required = false }: SelectProps) => {
	const [selectedValue, setSelectedValue] = React.useState<string>(value);

	const handleChange = (e: ChangeEvent<HTMLSelectElement>) => {
		const newValue = e.target.value;
		setSelectedValue(newValue);
		onChange(newValue);
	};

	return (
		<select
			required={required}
			className="stwc-bg-gray-100 stwc-rounded-md stwc-cursor-text stwc-p-2 stwc-text-sm stwc-font-normal stwc-border-0 stwc-outline-none focus:stwc-ring-2 focus:stwc-ring-blue-400 focus:stwc-ring-opacity-50 stwc-transition-all"
			value={selectedValue}
			onChange={handleChange}
		>
			{items.map((item, index) => (
				<option key={index} value={item.value}>
					{item.label}
				</option>
			))}
		</select>
	);
};
