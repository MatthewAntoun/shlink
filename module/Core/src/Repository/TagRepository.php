<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\Repository;

use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Happyr\DoctrineSpecification\Repository\EntitySpecificationRepository;
use Happyr\DoctrineSpecification\Spec;
use Shlinkio\Shlink\Core\Entity\Tag;
use Shlinkio\Shlink\Core\Tag\Model\TagInfo;
use Shlinkio\Shlink\Core\Tag\Model\TagsListFiltering;
use Shlinkio\Shlink\Core\Tag\Spec\CountTagsWithName;
use Shlinkio\Shlink\Rest\ApiKey\Role;
use Shlinkio\Shlink\Rest\ApiKey\Spec\WithApiKeySpecsEnsuringJoin;
use Shlinkio\Shlink\Rest\Entity\ApiKey;

use function Functional\map;
use function is_object;
use function method_exists;
use function sprintf;
use function strlen;
use function strpos;
use function substr_replace;

use const PHP_INT_MAX;

class TagRepository extends EntitySpecificationRepository implements TagRepositoryInterface
{
    private const PARAM_PLACEHOLDER = '?';

    public function deleteByName(array $names): int
    {
        if (empty($names)) {
            return 0;
        }

        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->delete(Tag::class, 't')
           ->where($qb->expr()->in('t.name', $names));

        return $qb->getQuery()->execute();
    }

    /**
     * @return TagInfo[]
     */
    public function findTagsWithInfo(?TagsListFiltering $filtering = null): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $subQb = $this->createQueryBuilder('t');
        $subQb->select('t.id', 't.name')
              ->orderBy('t.name', $filtering?->orderBy()?->orderDirection() ?? 'ASC') // TODO Make filed dynamic
              ->setMaxResults($filtering?->limit() ?? PHP_INT_MAX)
              ->setFirstResult($filtering?->offset() ?? 0);

        $searchTerm = $filtering?->searchTerm();
        if ($searchTerm !== null) {
            $subQb->andWhere($subQb->expr()->like('t.name', $conn->quote('%' . $searchTerm . '%')));
        }

        $apiKey = $filtering?->apiKey();
        $this->applySpecification($subQb, $apiKey?->spec(false, 'shortUrls'), 't');

        $subQuery = $subQb->getQuery();
        $subQuerySql = $subQuery->getSQL();

        // Sadly, we need to manually interpolate the params in the query replacing the placeholders, as this is going
        // to be used as a sub-query in a native query. There's no need to sanitize, though.
        foreach ($subQuery->getParameters() as $param) {
            $value = $param->getValue();
            $pos = strpos($subQuerySql, self::PARAM_PLACEHOLDER);
            $subQuerySql = substr_replace(
                $subQuerySql,
                sprintf('\'%s\'', is_object($value) && method_exists($value, 'getId') ? $value->getId() : $value),
                $pos === false ? -1 : $pos,
                strlen(self::PARAM_PLACEHOLDER),
            );
        }

        // A native query builder needs to be used here, because DQL and ORM query builders do not support
        // sub-queries at "from" and "join" level.
        // If no sub-query is used, the whole list is loaded even with pagination, making it very inefficient.
        $nativeQb = $conn->createQueryBuilder();
        $nativeQb
            ->select(
                't.id_0 AS id',
                't.name_1 AS name',
                'COUNT(DISTINCT s.id) AS short_urls_count',
                'COUNT(DISTINCT v.id) AS visits_count',
            )
            ->from('(' . $subQuerySql . ')', 't')
            ->leftJoin('t', 'short_urls_in_tags', 'st', $nativeQb->expr()->eq('t.id_0', 'st.tag_id'))
            ->leftJoin('st', 'short_urls', 's', $nativeQb->expr()->eq('s.id', 'st.short_url_id'))
            ->leftJoin('st', 'visits', 'v', $nativeQb->expr()->eq('s.id', 'v.short_url_id'))
            ->groupBy('t.id_0', 't.name_1')
            ->orderBy('t.name_1', $filtering?->orderBy()?->orderDirection() ?? 'ASC'); // TODO Make field dynamic

        // Apply API key role conditions to the native query too, as they will affect the amounts on the aggregates
        $apiKey?->mapRoles(fn (string $roleName, array $meta) => match ($roleName) {
            Role::DOMAIN_SPECIFIC => $nativeQb->andWhere(
                $nativeQb->expr()->eq('s.domain_id', $conn->quote(Role::domainIdFromMeta($meta))),
            ),
            Role::AUTHORED_SHORT_URLS => $nativeQb->andWhere(
                $nativeQb->expr()->eq('s.author_api_key_id', $conn->quote($apiKey->getId())),
            ),
            default => $nativeQb,
        });

        $rsm = new ResultSetMappingBuilder($this->getEntityManager());
        $rsm->addRootEntityFromClassMetadata(Tag::class, 't');
        $rsm->addScalarResult('short_urls_count', 'shortUrlsCount');
        $rsm->addScalarResult('visits_count', 'visitsCount');

        return map(
            $this->getEntityManager()->createNativeQuery($nativeQb->getSQL(), $rsm)->getResult(),
            static fn (array $row) => new TagInfo($row[0], (int) $row['shortUrlsCount'], (int) $row['visitsCount']),
        );
    }

    public function tagExists(string $tag, ?ApiKey $apiKey = null): bool
    {
        $result = (int) $this->matchSingleScalarResult(Spec::andX(
            new CountTagsWithName($tag),
            new WithApiKeySpecsEnsuringJoin($apiKey),
        ));

        return $result > 0;
    }
}
