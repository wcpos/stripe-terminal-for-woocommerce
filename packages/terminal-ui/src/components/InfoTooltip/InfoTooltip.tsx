import { type HTMLAttributes } from 'react';
import { cn } from '../lib/utils';

interface InfoTooltipProps extends HTMLAttributes<HTMLDivElement> {
	text: string;
}

export const InfoTooltip = ({ text, className, ...props }: InfoTooltipProps) => {
	return (
		<div className="relative inline-block" {...props}>
			<div
				className={cn(
					'h-4 w-4 rounded-full border border-[#E3E8EE] bg-[#F7FAFC] text-center text-[11px] leading-[15px] text-[#697386] hover:bg-[#E3E8EE]',
					className
				)}
			>
				?
			</div>
			<div className="absolute bottom-[calc(100%+4px)] left-1/2 hidden -translate-x-1/2 rounded bg-[#1A1F36] px-2 py-1 text-xs text-white hover:block">
				{text}
				<div className="absolute left-1/2 top-full h-2 w-2 -translate-x-1/2 rotate-45 bg-[#1A1F36]" />
			</div>
		</div>
	);
};
