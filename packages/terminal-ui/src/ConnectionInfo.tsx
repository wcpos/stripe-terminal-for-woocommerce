import React from 'react';
import { Button } from './components/Button/Button';
import { Icon } from './components/Icon/Icon';
import { Group } from './components/Group/Group';
type ConnectionInfoProps = {
	reader: { label: string } | null;
	onClickDisconnect: () => void;
};

export const ConnectionInfo = ({ reader, onClickDisconnect }: ConnectionInfoProps) => {
	return (
		<Group>
			<div className="stwc-p-4 stwc-flex stwc-items-center stwc-justify-between">
				<div className="stwc-flex stwc-items-center stwc-space-x-2">
					<Icon name="keypad" className={reader ? 'stwc-text-green-500' : 'stwc-text-red-500'} />
					<span
						className={`stwc-truncate stwc-text-sm ${reader ? 'stwc-text-gray-800' : 'stwc-text-gray-400'}`}
					>
						{reader ? reader.label : 'No reader connected'}
					</span>
				</div>
				{reader && (
					<Button onClick={onClickDisconnect} variant="text">
						Disconnect
					</Button>
				)}
			</div>
		</Group>
	);
};
