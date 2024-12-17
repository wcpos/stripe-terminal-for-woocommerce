import { type HTMLAttributes } from 'react';
import { cn } from '../../lib/utils';

interface ReaderIconProps extends HTMLAttributes<HTMLDivElement> {
	readerType?: 'CHIPPER_2X' | 'STRIPE_M2' | 'VERIFONE_P400' | 'WISECUBE' | 'STRIPE_S700';
	connected?: boolean;
}

export const ReaderIcon = ({
	readerType,
	connected = false,
	className,
	...props
}: ReaderIconProps) => {
	return (
		<div
			className={cn(
				'relative flex h-12 w-12 items-center justify-center rounded-lg border border-gray-200 bg-white p-2',
				className
			)}
			{...props}
		>
			{/* Reader icon */}
			<svg
				className="h-full w-full"
				viewBox="0 0 32 32"
				fill="none"
				xmlns="http://www.w3.org/2000/svg"
			>
				<path
					fillRule="evenodd"
					clipRule="evenodd"
					d="M10.5 7a.5.5 0 00-.5.5v17a.5.5 0 00.5.5h11a.5.5 0 00.5-.5v-17a.5.5 0 00-.5-.5h-11zm0-2a2.5 2.5 0 00-2.5 2.5v17a2.5 2.5 0 002.5 2.5h11a2.5 2.5 0 002.5-2.5v-17a2.5 2.5 0 00-2.5-2.5h-11z"
					fill={connected ? '#586ADA' : '#697386'}
				/>
				<path
					fillRule="evenodd"
					clipRule="evenodd"
					d="M13 21.5a.5.5 0 01.5-.5h5a.5.5 0 010 1h-5a.5.5 0 01-.5-.5z"
					fill={connected ? '#586ADA' : '#697386'}
				/>
			</svg>

			{/* Connection status indicator */}
			<div
				className={cn(
					'absolute -right-1 -top-1 h-3 w-3 rounded-full border-2 border-white',
					connected ? 'bg-green-500' : 'bg-gray-300'
				)}
			/>
		</div>
	);
};
