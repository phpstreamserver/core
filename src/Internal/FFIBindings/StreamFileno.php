<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\FFIBindings;

/**
 * Extracts the OS file descriptor exposed by a PHP stream resource using internal Zend Engine and PHP Stream APIs via FFI.
 *
 * @internal
 */
final class StreamFileno
{
    private const PHP_STREAM_AS_FD = 1;
    private const PHP_STREAM_AS_FD_FOR_SELECT = 3;

    // https://github.com/chopins/ffi-ext/blob/master/src/php.h
    private const CDEF = <<<'CDEF'
        typedef uint64_t zend_ulong;

        typedef struct _zend_refcounted_h {
            uint32_t refcount;
            union {
                uint32_t type_info;
            } u;
        } zend_refcounted_h;

        typedef struct _zval_struct zval;
        typedef struct _zend_resource zend_resource;
        typedef struct _php_stream php_stream;
        typedef struct _zend_array HashTable;

        struct _zval_struct {
            union {
                zend_resource *res;
            } value;
            union {
                uint32_t type_info;
            } u1;
            union {
                uint32_t next;
            } u2;
        };

        typedef struct _Bucket {
            zval       val;
            zend_ulong h;
            void      *key;
        } Bucket;

        struct _zend_array {
            zend_refcounted_h gc;
            union {
                uint32_t flags;
            } u;
            uint32_t    nTableMask;
            Bucket     *arData;
        };

        HashTable *zend_rebuild_symbol_table(void);
        HashTable *zend_array_dup(HashTable *source);
        void zend_array_destroy(HashTable *ht);
        void *zend_fetch_resource2(zend_resource *res, const char *resource_type_name, int resource_type1, int resource_type2);
        int php_file_le_stream(void);
        int php_file_le_pstream(void);
        int _php_stream_cast(php_stream *stream, int castas, void **ret, int show_err);
    CDEF;

    private static \FFI $ffi;

    private static function ffi(): \FFI
    {
        return self::$ffi ??= \FFI::cdef(self::CDEF);
    }

    public static function get(mixed $resource): int|null
    {
        if (!\is_resource($resource)) {
            throw new \InvalidArgumentException(\sprintf('Expected resource, %s given', \get_debug_type($resource)));
        }

        $symbolTable = self::ffi()->zend_rebuild_symbol_table();
        $symbolHashTable = self::ffi()->zend_array_dup($symbolTable);

        try {
            $zval = $symbolHashTable->arData->val;
            $zresource = $zval->value->res;
            $phpStream = self::ffi()->zend_fetch_resource2($zresource, null, self::ffi()->php_file_le_stream(), self::ffi()->php_file_le_pstream());

            if ($phpStream === null || \FFI::isNull($phpStream)) {
                return null;
            }

            $filenoCData = self::ffi()->new('int');
            $castTypeList = [self::PHP_STREAM_AS_FD, self::PHP_STREAM_AS_FD_FOR_SELECT];
            foreach ($castTypeList as $castType) {
                $filenoCData->cdata = -1;
                $status = self::ffi()->_php_stream_cast($phpStream, $castType, self::ffi()->cast('void *', \FFI::addr($filenoCData)), 0);
                if ($status === 0 && $filenoCData->cdata >= 0) {
                    return $filenoCData->cdata;
                }
            }

            return null;
        } finally {
            self::ffi()->zend_array_destroy($symbolHashTable);
        }
    }
}
