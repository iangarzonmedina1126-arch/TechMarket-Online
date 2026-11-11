<?php
/** Borrado transaccional de un registro y de sus dependencias reales por FK.
 * Solo debe invocarse después de autorizar explícitamente al rol Jefe.
 */
function tm_delete_row_with_dependents(PDO $db, string $table, array $where, array &$visited = [], int $depth = 0): void {
    if ($depth > 20) throw new RuntimeException('La relación entre registros es demasiado profunda para eliminarla con seguridad.');
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !$where) throw new RuntimeException('Tabla o identificador de borrado inválido.');
    $schema = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    $conditions=[]; $values=[];
    foreach ($where as $column=>$value) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/',(string)$column)) throw new RuntimeException('Columna de borrado inválida.');
        $conditions[]='`'. $column .'` <=> ?'; $values[]=$value;
    }
    $stmt=$db->prepare('SELECT * FROM `'.$table.'` WHERE '.implode(' AND ',$conditions).' LIMIT 1');
    $stmt->execute($values); $row=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return;
    $identity=json_encode([$table,array_intersect_key($row,$where)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $key=hash('sha256',(string)$identity);
    if (isset($visited[$key])) return;
    $visited[$key]=true;

    $fk=$db->prepare("SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND REFERENCED_TABLE_NAME=? AND REFERENCED_TABLE_SCHEMA=? ORDER BY TABLE_NAME,COLUMN_NAME");
    $fk->execute([$schema,$table,$schema]);
    foreach ($fk->fetchAll(PDO::FETCH_ASSOC) as $rel) {
        $child=(string)$rel['TABLE_NAME']; $childCol=(string)$rel['COLUMN_NAME']; $parentCol=(string)$rel['REFERENCED_COLUMN_NAME'];
        if (!isset($row[$parentCol]) || !preg_match('/^[a-zA-Z0-9_]+$/',$child) || !preg_match('/^[a-zA-Z0-9_]+$/',$childCol)) continue;
        $pkq=$db->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION");
        $pkq->execute([$schema,$child]); $pk=array_column($pkq->fetchAll(PDO::FETCH_ASSOC),'COLUMN_NAME');
        $cq=$db->prepare('SELECT * FROM `'.$child.'` WHERE `'.$childCol.'` <=> ?'); $cq->execute([$row[$parentCol]]); $children=$cq->fetchAll(PDO::FETCH_ASSOC);
        foreach ($children as $childRow) {
            if ($pk) {
                $childWhere=[]; foreach($pk as $pkCol) $childWhere[$pkCol]=$childRow[$pkCol];
                tm_delete_row_with_dependents($db,$child,$childWhere,$visited,$depth+1);
            } else {
                // Las tablas de relación sin PK se limpian por su FK, sin tocar otros registros.
                $del=$db->prepare('DELETE FROM `'.$child.'` WHERE `'.$childCol.'` <=> ?'); $del->execute([$row[$parentCol]]);
            }
        }
    }
    $del=$db->prepare('DELETE FROM `'.$table.'` WHERE '.implode(' AND ',$conditions)); $del->execute($values);
}
