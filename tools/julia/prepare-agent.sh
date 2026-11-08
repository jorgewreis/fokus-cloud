#!/usr/bin/env bash
set -euo pipefail

# Usar caminhos absolutos fora do HOME privado do runner. Acesso relativo a
# partir de um cwd herdado não comprova travessia dos diretórios ancestrais.
julia_workspace="${GITHUB_WORKSPACE:?GITHUB_WORKSPACE ausente}"
sudo useradd --create-home --shell /bin/bash julia
sudo chmod 755 /home/julia
sudo -u julia mkdir -p /home/julia/.config
sudo mv "$julia_workspace/work" /home/julia/work
sudo mkdir /home/julia/julia-input /home/julia/julia-result
sudo cp "$julia_workspace/julia-input/task.json" /home/julia/julia-input/task.json
sudo chown -R root:root /home/julia/julia-input
sudo chmod 755 /home/julia/julia-input
sudo chmod 644 /home/julia/julia-input/task.json
sudo chown -R julia:julia /home/julia/work /home/julia/julia-result
sudo -u julia test -r /opt/julia/task-prompt.md
sudo -u julia test -r /home/julia/julia-input/task.json
sudo -u julia test -r /home/julia/work/AGENTS.md
sudo -u julia test -w /home/julia/work
sudo -u julia test -w /home/julia/julia-result
sudo -u julia /bin/bash -c 'cd /home/julia/work && git status --short && node --version && printf accessible > /home/julia/julia-result/preflight.txt'
if sudo -u julia sudo -n true 2>/dev/null; then
  echo 'Agent account unexpectedly has sudo privileges.' >&2
  exit 1
fi
