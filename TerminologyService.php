<?php

declare(strict_types=1);

namespace UWMadison\ClinicalTerminologyMapper;

use PDO;
use Throwable;
use RuntimeException;

/**
 * Service to query pre-compiled FHIR R4 and US Core Terminology from SQLite.
 * 
 * Note: This service does NOT create tables, schema, or seed default records.
 * The SQLite database must be built and initialized by TerminologyBuilderService
 * via the Control Center or the CLI builder script.
 */
class TerminologyService
{
    private ?PDO $db = null;
    private string $dbPath;

    public function __construct(string $dbPath)
    {
        $this->dbPath = $dbPath;
    }

    public function isAvailable(): bool
    {
        return file_exists($this->dbPath) && filesize($this->dbPath) > 50000;
    }

    public function getAvailablePackages(): array
    {
        if (!$this->isAvailable()) return [];
        try {
            $db = $this->getDb();
            $stmt = $db->query("SELECT package, COUNT(*) as cnt FROM valuesets GROUP BY package ORDER BY package");
            $pkgs = [];
            while ($row = $stmt->fetch()) {
                if ((int)$row['cnt'] > 0) {
                    $pkgs[] = (string)$row['package'];
                }
            }
            return $pkgs;
        } catch (Throwable $e) {
            return [];
        }
    }

    public function getDb(): PDO
    {
        if ($this->db !== null) return $this->db;

        if (!$this->isAvailable()) {
            throw new RuntimeException(
                "The FHIR Terminology Database is not initialized or was not found at '{$this->dbPath}'. " .
                "Please contact your REDCap System Administrator to build the database from the Control Center."
            );
        }

        $this->db = new PDO('sqlite:' . $this->dbPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec('PRAGMA journal_mode = WAL;');
        $this->db->exec('PRAGMA synchronous = NORMAL;');
        $this->db->exec('PRAGMA query_only = ON;');
        $this->db->exec('PRAGMA mmap_size = 268435456;');
        $this->db->exec('PRAGMA cache_size = -64000;');
        $this->db->exec('PRAGMA temp_store = MEMORY;');

        return $this->db;
    }

    public function findValueSetsByConcept(string $code, ?string $system = null): array
    {
        if (!$this->isAvailable()) return [];

        $db = $this->getDb();
        $code = trim($code);
        if ($code === '') return [];

        if (!empty($system)) {
            $stmt = $db->prepare("
                SELECT DISTINCT v.id, v.title, v.url, v.urn, c.code, c.display, v.package
                FROM concepts c
                JOIN valuesets v ON c.valueset_id = v.id AND c.package = v.package
                WHERE c.code = :code AND (c.system = :system OR v.url = :system OR v.urn = :system)
                ORDER BY v.title ASC
                LIMIT 10
            ");
            $stmt->execute([':code' => $code, ':system' => $system]);
            $res = $stmt->fetchAll();
            if (!empty($res)) return $res;
        }

        $stmt = $db->prepare("
            SELECT DISTINCT v.id, v.title, v.url, v.urn, c.code, c.display, v.package
            FROM concepts c
            JOIN valuesets v ON c.valueset_id = v.id AND c.package = v.package
            WHERE c.code = :code
            ORDER BY v.title ASC
            LIMIT 10
        ");
        $stmt->execute([':code' => $code]);
        return $stmt->fetchAll();
    }

    /**
     * Search ValueSets by keyword or return default sets.
     * 
     * Supports both calling styles:
     * - searchValuesets(string $query, ?array $packages = null, int $limit = 25): array
     * - searchValuesets(string $query, int $limit = 25): array
     * 
     * @param string $query
     * @param array|int|null $packages Filter by packages, or limit if int
     * @param int $limit Max results to return
     * @return array
     */
    public function searchValuesets(string $query, array|int|null $packages = null, int $limit = 25): array
    {
        if (is_int($packages)) {
            $limit = $packages;
            $packages = null;
        }

        if (!$this->isAvailable()) return [];

        $db = $this->getDb();
        $query = trim($query);

        $pkgFilter = '';
        $params = [];
        if (!empty($packages)) {
            $placeholders = [];
            foreach ($packages as $i => $p) {
                $ph = ":p_{$i}";
                $placeholders[] = $ph;
                $params[$ph] = $p;
            }
            $pkgFilter = "v.package IN (" . implode(',', $placeholders) . ")";
        }

        if ($query === '') {
            $sql = "SELECT v.* FROM valuesets v" . ($pkgFilter ? " WHERE {$pkgFilter}" : "") . " ORDER BY (v.concept_count = 0) ASC, v.title ASC LIMIT :limit";
            $stmt = $db->prepare($sql);
            foreach ($params as $k => $v) $stmt->bindValue($k, $v);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        }

        $cleanFtsTerm = trim(preg_replace('/[^a-zA-Z0-9_\*\-]/', ' ', $query));
        $results = [];
        $existingKeys = [];

        // Try FTS5 on valuesets_fts first if tokens exist
        if (!empty($cleanFtsTerm)) {
            $ftsTokens = array_filter(explode(' ', $cleanFtsTerm));
            if (!empty($ftsTokens)) {
                $matchParts = array_map(fn($t) => rtrim($t, '*') . '*', $ftsTokens);
                $ftsMatch = implode(' AND ', $matchParts);

                $ftsWhere = "valuesets_fts MATCH :match";
                if ($pkgFilter) $ftsWhere .= " AND {$pkgFilter}";

                $ftsSql = "
                    SELECT v.*, bm25(valuesets_fts) as rank
                    FROM valuesets_fts f
                    JOIN valuesets v ON f.id = v.id AND f.package = v.package
                    WHERE {$ftsWhere}
                    ORDER BY
                        CASE WHEN v.id = :exact OR v.urn = :exact OR v.url = :exact THEN 0 WHEN v.title LIKE :prefix THEN 1 ELSE 2 END,
                        (v.concept_count = 0) ASC,
                        rank ASC,
                        v.title ASC
                    LIMIT :limit
                ";

                try {
                    $ftsStmt = $db->prepare($ftsSql);
                    foreach ($params as $k => $v) $ftsStmt->bindValue($k, $v);
                    $ftsStmt->bindValue(':match', $ftsMatch, PDO::PARAM_STR);
                    $ftsStmt->bindValue(':exact', $query, PDO::PARAM_STR);
                    $ftsStmt->bindValue(':prefix', $query . '%', PDO::PARAM_STR);
                    $ftsStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                    $ftsStmt->execute();
                    $results = $ftsStmt->fetchAll();
                    foreach ($results as $r) {
                        $existingKeys[$r['id'] . '::' . $r['package']] = true;
                    }
                } catch (Throwable $e) {
                    // Fall back if FTS query syntax error
                }
            }
        }

        // If FTS on valuesets yielded no results, search concepts_fts for matching concept codes or displays
        if (empty($results) && !empty($cleanFtsTerm)) {
            $cleanTerm = preg_replace('/[^a-zA-Z0-9_\*\-]/', '', $query);
            if (!empty($cleanTerm)) {
                $ftsWhere = "concepts_fts MATCH :match";
                if ($pkgFilter) $ftsWhere .= " AND {$pkgFilter}";

                $ftsStmt = $db->prepare("
                    SELECT DISTINCT v.* FROM concepts_fts f
                    JOIN valuesets v ON f.valueset_id = v.id AND f.package = v.package
                    WHERE {$ftsWhere}
                    LIMIT :limit
                ");
                foreach ($params as $k => $v) $ftsStmt->bindValue($k, $v);
                $ftsStmt->bindValue(':match', $cleanTerm . '*', PDO::PARAM_STR);
                $ftsStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                try {
                    $ftsStmt->execute();
                    while ($row = $ftsStmt->fetch()) {
                        $k = $row['id'] . '::' . $row['package'];
                        if (!isset($existingKeys[$k])) {
                            $results[] = $row;
                            $existingKeys[$k] = true;
                            if (count($results) >= $limit) break;
                        }
                    }
                } catch (Throwable $e) {
                    // Ignore FTS syntax errors
                }
            }
        }

        // If still no results (or FTS wasn't possible due to symbols or short substring), fall back to LIKE
        if (empty($results)) {
            $term = '%' . $query . '%';
            $where = "v.id LIKE :term OR v.title LIKE :term OR v.name LIKE :term OR v.description LIKE :term OR v.url LIKE :term OR v.urn LIKE :term";
            if ($pkgFilter) $where = "({$where}) AND {$pkgFilter}";

            $stmt = $db->prepare("
                SELECT v.* FROM valuesets v
                WHERE {$where}
                ORDER BY
                    CASE WHEN v.id = :exact OR v.urn = :exact OR v.url = :exact THEN 0 WHEN v.title LIKE :prefix THEN 1 ELSE 2 END,
                    (v.concept_count = 0) ASC,
                    v.title ASC
                LIMIT :limit
            ");
            foreach ($params as $k => $v) $stmt->bindValue($k, $v);
            $stmt->bindValue(':term', $term, PDO::PARAM_STR);
            $stmt->bindValue(':exact', $query, PDO::PARAM_STR);
            $stmt->bindValue(':prefix', $query . '%', PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch()) {
                $k = $row['id'] . '::' . $row['package'];
                if (!isset($existingKeys[$k])) {
                    $results[] = $row;
                    $existingKeys[$k] = true;
                    if (count($results) >= $limit) break;
                }
            }
        }

        return $results;
    }

    public function getValueSet(string $identifier, ?string $package = null, ?array $allowedPackages = null): ?array
    {
        if (!$this->isAvailable()) return null;

        $db = $this->getDb();
        $idClean = trim($identifier);

        if (str_contains($idClean, '::')) {
            [$idClean, $parsedPkg] = explode('::', $idClean, 2);
            if (!empty($parsedPkg)) $package = $parsedPkg;
        }

        // Normalize raw OIDs (e.g. 2.16.840... -> urn:oid:2.16.840...)
        $urnCandidate = str_starts_with($idClean, 'urn:') ? $idClean : 'urn:oid:' . $idClean;

        $conditions = ["(id = :id OR url = :url OR urn = :urn1 OR urn = :urn2)"];
        $params = [
            ':id' => $idClean,
            ':url' => $idClean,
            ':urn1' => $idClean,
            ':urn2' => $urnCandidate
        ];

        if (!empty($package)) {
            $conditions[] = "package = :pkg";
            $params[':pkg'] = $package;
            $orderBy = "title ASC";
        } elseif (!empty($allowedPackages)) {
            $placeholders = [];
            foreach ($allowedPackages as $idx => $p) {
                $ph = ":pkg_" . $idx;
                $placeholders[] = $ph;
                $params[$ph] = $p;
            }
            $conditions[] = "package IN (" . implode(',', $placeholders) . ")";
            $orderBy = "CASE WHEN package = 'US Core v3.1.1' THEN 0 WHEN package = 'FHIR R4' THEN 1 ELSE 2 END, title ASC";
        } else {
            $orderBy = "CASE WHEN package = 'US Core v3.1.1' THEN 0 WHEN package = 'FHIR R4' THEN 1 ELSE 2 END, title ASC";
        }

        $where = implode(' AND ', $conditions);
        $stmt = $db->prepare("SELECT v.* FROM valuesets v WHERE {$where} ORDER BY {$orderBy} LIMIT 1");
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getConceptsForValueset(string $identifier, ?string $package = null, ?array $allowedPackages = null): array
    {
        if (!$this->isAvailable()) return [];

        $vs = $this->getValueSet($identifier, $package, $allowedPackages);
        if (!$vs) return [];

        $db = $this->getDb();
        $stmt = $db->prepare("SELECT system, code, display, definition FROM concepts WHERE valueset_id = :id AND package = :pkg ORDER BY sort_order ASC, display ASC");
        $stmt->execute([':id' => $vs['id'], ':pkg' => $vs['package']]);
        return $stmt->fetchAll();
    }

    public function searchConcepts(string $valuesetIdentifier, string $term, int $limit = 30, ?array $allowedPackages = null): array
    {
        if (!$this->isAvailable()) return [];

        $vs = $this->getValueSet($valuesetIdentifier, null, $allowedPackages);
        $valuesetId = $vs ? $vs['id'] : $valuesetIdentifier;
        $pkg = $vs ? $vs['package'] : null;

        $db = $this->getDb();
        $term = trim($term);
        if ($term === '') return [];

        $ftsQuery = preg_replace('/[^a-zA-Z0-9_\*\-]/', '', $term) . '*';

        if ($pkg !== null) {
            $stmt = $db->prepare("
                SELECT c.code, c.display
                FROM concepts_fts f
                JOIN concepts c ON c.valueset_id = f.valueset_id AND c.code = f.code AND c.package = f.package
                WHERE f.valueset_id = :vs_id AND f.package = :pkg AND concepts_fts MATCH :query
                ORDER BY rank
                LIMIT :limit
            ");
            $stmt->bindValue(':vs_id', $valuesetId, PDO::PARAM_STR);
            $stmt->bindValue(':pkg', $pkg, PDO::PARAM_STR);
            $stmt->bindValue(':query', $ftsQuery, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        } else {
            $stmt = $db->prepare("
                SELECT c.code, c.display
                FROM concepts_fts f
                JOIN concepts c ON c.valueset_id = f.valueset_id AND c.code = f.code AND c.package = f.package
                WHERE f.valueset_id = :vs_id AND concepts_fts MATCH :query
                ORDER BY rank
                LIMIT :limit
            ");
            $stmt->bindValue(':vs_id', $valuesetId, PDO::PARAM_STR);
            $stmt->bindValue(':query', $ftsQuery, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }

        try {
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            $like = '%' . $term . '%';
            if ($pkg !== null) {
                $fallback = $db->prepare("SELECT code, display FROM concepts WHERE valueset_id = ? AND package = ? AND (code LIKE ? OR display LIKE ?) LIMIT ?");
                $fallback->execute([$valuesetId, $pkg, $like, $like, $limit]);
            } else {
                $fallback = $db->prepare("SELECT code, display FROM concepts WHERE valueset_id = ? AND (code LIKE ? OR display LIKE ?) LIMIT ?");
                $fallback->execute([$valuesetId, $like, $like, $limit]);
            }
            return $fallback->fetchAll();
        }
    }

    public function getLabelForConcept(string $valuesetIdentifier, string $code, ?array $allowedPackages = null): ?string
    {
        if (!$this->isAvailable()) return null;

        $vs = $this->getValueSet($valuesetIdentifier, null, $allowedPackages);
        $valuesetId = $vs ? $vs['id'] : $valuesetIdentifier;
        $pkg = $vs ? $vs['package'] : null;

        $db = $this->getDb();
        if ($pkg !== null) {
            $stmt = $db->prepare("SELECT display FROM concepts WHERE valueset_id = ? AND package = ? AND code = ? LIMIT 1");
            $stmt->execute([$valuesetId, $pkg, $code]);
        } else {
            $stmt = $db->prepare("SELECT display FROM concepts WHERE valueset_id = ? AND code = ? LIMIT 1");
            $stmt->execute([$valuesetId, $code]);
        }
        $row = $stmt->fetch();
        return $row ? $row['display'] : null;
    }
}
