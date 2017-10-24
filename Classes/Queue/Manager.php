<?php

namespace WEBcoast\VersatileCrawler\Queue;


use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WEBcoast\VersatileCrawler\Domain\Model\Item;

class Manager implements SingletonInterface
{
    const QUEUE_TABLE = 'tx_versatilecrawler_domain_model_queue_item';

    /**
     * Add or update a item for a given configuration and identifier.
     * Sets state to PENDING, and clear message and data.
     *
     * @param \WEBcoast\VersatileCrawler\Domain\Model\Item $item
     *
     * @return bool
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    public function addOrUpdateItem(Item $item)
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::QUEUE_TABLE);
        $count = $connection->count(
            '*',
            self::QUEUE_TABLE,
            ['configuration' => $item->getConfiguration(), 'identifier' => $item->getIdentifier()]
        );
        if ($count === 1) {
            $changedRows = $connection->update(
                self::QUEUE_TABLE,
                ['tstamp' => time(), 'state' => Item::STATE_PENDING, 'message' => '', 'data' => json_encode($item->getData()), 'hash' => ''],
                ['configuration' => $item->getConfiguration(), 'identifier' => $item->getIdentifier()]
            );

            return $changedRows === 1;
        } else {
            $insertedRows = $connection->insert(
                self::QUEUE_TABLE,
                [
                    'configuration' => $item->getConfiguration(),
                    'identifier' => $item->getIdentifier(),
                    'tstamp' => time(),
                    'state' => Item::STATE_PENDING,
                    'message' => '',
                    'data' => json_encode($item->getData())
                ]
            );

            return $insertedRows === 1;
        }
    }

    /**
     * Updates the state, message and data of a given
     *
     * @param \WEBcoast\VersatileCrawler\Domain\Model\Item $item
     *
     * @return bool
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \RuntimeException
     */
    public function updateState($item)
    {
        if ($item->getState() !== Item::STATE_SUCCESS && $item->getState() !== Item::STATE_ERROR) {
            throw new \RuntimeException('The state can only be changed to SUCCESS or ERROR', 1508491824);
        }
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::QUEUE_TABLE);
        $changedRows = $connection->update(
            self::QUEUE_TABLE,
            ['state' => $item->getState(), 'message' => $item->getMessage(), 'hash' => ''],
            ['configuration' => $item->getConfiguration(), 'identifier' => $item->getIdentifier()]
        );

        return $changedRows === 1;
    }

    /**
     * Return DBAL statement container all pending items
     *
     * @param int $limit Limit of items to be fetched
     *
     * @return \Doctrine\DBAL\Driver\Statement
     */
    public function getPendingItems($limit = null)
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::QUEUE_TABLE);

        return $connection->select(
            ['*'],
            self::QUEUE_TABLE,
            ['state' => Item::STATE_PENDING],
            [],
            ['tstamp' => 'ASC'],
            $limit !== null ? (int)$limit : 0
        );
    }

    public function getAllItems()
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::QUEUE_TABLE);

        return $connection->select(
            ['*'],
            self::QUEUE_TABLE,
            [],
            [],
            ['tstamp' => 'ASC']
        );
    }

    public function getFinishedItems()
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::QUEUE_TABLE);
        $query = $connection->createQueryBuilder()->select('*')->from(self::QUEUE_TABLE);
        $query->where(
            $query->expr()->orX(
                'state=' . Item::STATE_SUCCESS,
                'state=' . Item::STATE_ERROR
            )
        );
        $query->orderBy('tstamp', 'ASC');

        return $query->execute();
    }

    public function getSuccessfulItems()
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::QUEUE_TABLE);

        return $connection->select(
            ['*'],
            self::QUEUE_TABLE,
            ['state' => Item::STATE_SUCCESS],
            [],
            ['tstamp' => 'ASC']
        );
    }

    public function getFailedItems()
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::QUEUE_TABLE);

        return $connection->select(
            ['*'],
            self::QUEUE_TABLE,
            ['state' => Item::STATE_ERROR],
            [],
            ['tstamp' => 'ASC']
        );
    }

    /**
     * @return int
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    public function countAllItems()
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::QUEUE_TABLE);

        return $connection->count(
            '*',
            self::QUEUE_TABLE,
            []
        );
    }

    /**
     * @return int
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    public function countFinishedItems()
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::QUEUE_TABLE);
        $query = $connection->createQueryBuilder()->count('*')->from(self::QUEUE_TABLE);
        $query->where(
            $query->expr()->orX(
                'state=' . Item::STATE_SUCCESS,
                'state=' . Item::STATE_ERROR
            )
        );

        return $query->execute()->fetchColumn(0);
    }

    public function prepareItemForProcessing($configuration, $identifier, $hash)
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::QUEUE_TABLE);

        return $connection->update(
            self::QUEUE_TABLE,
            ['hash' => $hash, 'state' => Item::STATE_IN_PROGRESS],
            ['configuration' => $configuration, 'identifier' => $identifier, 'state' => Item::STATE_PENDING]
        );
    }

    public function getItemForProcessing($hash)
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::QUEUE_TABLE);

        return $connection->select(['*'], self::QUEUE_TABLE, ['hash' => $hash, 'state' => Item::STATE_IN_PROGRESS]);
    }

    public function getFromRecord($record)
    {
        return new Item(
            $record['configuration'],
            $record['identifier'],
            $record['state'],
            $record['message'],
            json_decode($record['data'], true),
            $record['hash']
        );
    }
}