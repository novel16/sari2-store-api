#!/usr/bin/env python3
"""Extract only bounded regular files/directories; never trust uploaded tar paths."""
import pathlib
import sys
import tarfile


def unpack(archive, destination):
    root = pathlib.Path(destination).resolve()
    with tarfile.open(archive, 'r:gz') as bundle:
        members = bundle.getmembers()
        if len(members) > 50000 or sum(m.size for m in members) > 1024**3:
            raise ValueError('Release exceeds extraction limit')
        for member in members:
            name = pathlib.PurePosixPath(member.name)
            if name.is_absolute() or '..' in name.parts or not (member.isfile() or member.isdir()):
                raise ValueError('Unsafe archive member')
            if any(p in {'.env', '.git', 'storage'} or p.startswith('.env.') for p in name.parts):
                raise ValueError('Release contains protected data')
            if not (root / member.name).resolve().is_relative_to(root):
                raise ValueError('Archive escapes release directory')
        bundle.extractall(root, members=members, filter='data')


if __name__ == '__main__':
    unpack(sys.argv[1], sys.argv[2])
