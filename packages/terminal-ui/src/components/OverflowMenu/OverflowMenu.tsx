import { type HTMLAttributes, useState, useRef, useEffect } from 'react';
import { cn } from '../lib/utils';

interface OverflowMenuProps extends HTMLAttributes<HTMLDivElement> {
	items: React.ReactNode[];
}

export const OverflowMenu = ({ items, className, ...props }: OverflowMenuProps) => {
	const [isOpen, setIsOpen] = useState(false);
	const menuRef = useRef<HTMLDivElement>(null);

	useEffect(() => {
		const handleClickOutside = (event: MouseEvent) => {
			if (menuRef.current && !menuRef.current.contains(event.target as Node)) {
				setIsOpen(false);
			}
		};

		document.addEventListener('mousedown', handleClickOutside);
		return () => document.removeEventListener('mousedown', handleClickOutside);
	}, []);

	return (
		<div ref={menuRef} className={cn('relative inline-block', className)} {...props}>
			<button
				onClick={() => setIsOpen(!isOpen)}
				className="stwc-flex stwc-h-6 stwc-w-6 stwc-items-center stwc-justify-center stwc-rounded-full stwc-text-[#697386] hover:stwc-bg-[#F7FAFC]"
			>
				<svg width="16" height="4" viewBox="0 0 16 4">
					<path
						d="M2 0C.9 0 0 .9 0 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm12 0c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zM8 0C6.9 0 6 .9 6 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"
						fill="currentColor"
					/>
				</svg>
			</button>

			{isOpen && (
				<div className="stwc-absolute stwc-right-0 stwc-top-[calc(100%+4px)] stwc-z-10 stwc-min-w-[160px] stwc-rounded-lg stwc-border stwc-border-[#E3E8EE] stwc-bg-white stwc-py-2 stwc-shadow-[0_50px_100px_-20px_rgba(50,50,93,0.25),0_30px_60px_-30px_rgba(0,0,0,0.3)]">
					{items}
				</div>
			)}
		</div>
	);
};
