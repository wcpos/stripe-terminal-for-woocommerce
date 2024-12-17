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

const Select = ({ value, items, onChange, required = false }: SelectProps) => {
	const [selectedValue, setSelectedValue] = React.useState<string>(value);

	const handleChange = (e: ChangeEvent<HTMLSelectElement>) => {
		const newValue = e.target.value;
		setSelectedValue(newValue);
		onChange(newValue);
	};

	return (
		<select
			required={required}
			className="bg-gray-100 rounded-md cursor-text p-2 text-sm font-normal border-0 outline-none focus:ring-2 focus:ring-blue-400 focus:ring-opacity-50 transition-all"
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

export default Select;
