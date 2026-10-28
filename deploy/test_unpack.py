import io
import pathlib
import tarfile
import tempfile
import unittest
from unpack import unpack


class ArchiveTests(unittest.TestCase):
    def check_archive(self, name, kind=tarfile.REGTYPE, valid=False):
        with tempfile.TemporaryDirectory() as directory:
            archive = pathlib.Path(directory) / 'release.tar.gz'
            target = pathlib.Path(directory) / 'target'
            target.mkdir()
            with tarfile.open(archive, 'w:gz') as bundle:
                member = tarfile.TarInfo(name)
                member.type = kind
                member.linkname = '/etc/passwd' if kind == tarfile.SYMTYPE else ''
                bundle.addfile(member, io.BytesIO(b''))
            if valid:
                unpack(archive, target)
                self.assertTrue((target / name).is_file())
            else:
                with self.assertRaises(ValueError):
                    unpack(archive, target)

    def test_regular_release(self):
        self.check_archive('app/Example.php', valid=True)

    def test_unsafe_members(self):
        for name in ['../escape', '/absolute', '.env', './.env.production', 'storage/logs/log', '.git/config']:
            with self.subTest(name=name):
                self.check_archive(name)
        self.check_archive('link', tarfile.SYMTYPE)
        self.check_archive('device', tarfile.CHRTYPE)


if __name__ == '__main__':
    unittest.main()
