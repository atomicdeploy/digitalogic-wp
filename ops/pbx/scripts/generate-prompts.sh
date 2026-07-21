#!/usr/bin/env bash
set -euo pipefail
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
pbx_dir="$(cd -- "$script_dir/.." && pwd -P)"
source_dir="$pbx_dir/prompts/fa-IR"
output_dir="${1:-$pbx_dir/build/prompts-fa-IR}"
engine="${PBX_TTS_ENGINE:-auto}"
piper_bin="${PBX_PIPER_BIN:-piper}"
piper_model="${PBX_PIPER_MODEL:-}"
piper_config="${PBX_PIPER_CONFIG:-}"
edge_voice="${PBX_EDGE_VOICE:-fa-IR-DilaraNeural}"
bgm_file="${PBX_BGM_FILE:-}"
bgm_volume="${PBX_BGM_VOLUME:-0.035}"

mixed_prompts=(pending-code enter-code verified invalid temporary-failure)
all_prompts=("${mixed_prompts[@]}")

command -v ffmpeg >/dev/null 2>&1
test -r "$bgm_file"
[[ "$bgm_volume" =~ ^(0(\.[0-9]{1,3})?|1(\.0{1,3})?)$ ]]
mkdir -p -- "$output_dir"
work_dir="$(mktemp -d)"
trap 'rm -rf -- "$work_dir"' EXIT

case "$engine" in
	auto)
		if [[ -n "$piper_model" ]] && command -v "$piper_bin" >/dev/null 2>&1; then
			engine=piper
		elif command -v edge-tts >/dev/null 2>&1; then
			engine=edge
		else
			printf '%s\n' 'Set PBX_PIPER_MODEL for local Piper, or explicitly install/select edge-tts.' >&2
			exit 20
		fi
		;;
	piper)
		command -v "$piper_bin" >/dev/null 2>&1
		test -r "$piper_model"
		if [[ -n "$piper_config" ]]; then test -r "$piper_config"; fi
		;;
	edge)
		command -v edge-tts >/dev/null 2>&1
		;;
	*)
		printf '%s\n' 'PBX_TTS_ENGINE must be auto, piper, or edge.' >&2
		exit 20
		;;
esac

for name in "${all_prompts[@]}"; do
	text_file="$source_dir/$name.txt"
	test -s "$text_file"
	if [[ "$engine" == piper ]]; then
		piper_args=(--model "$piper_model" --output_file "$work_dir/$name.source.wav")
		if [[ -n "$piper_config" ]]; then
			piper_args+=(--config "$piper_config")
		fi
		"$piper_bin" "${piper_args[@]}" < "$text_file"
	else
		edge-tts --voice "$edge_voice" --file "$text_file" --write-media "$work_dir/$name.source.mp3"
	fi
	source_audio="$(find "$work_dir" -maxdepth 1 -type f -name "$name.source.*" -print -quit)"
	test -n "$source_audio"
	render_audio="$source_audio"
	if [[ " ${mixed_prompts[*]} " == *" $name "* ]]; then
		tail_seconds=1
		ffmpeg -nostdin -hide_banner -loglevel error -y \
			-stream_loop -1 -i "$bgm_file" -i "$source_audio" \
			-filter_complex "[0:a]volume=${bgm_volume},highpass=f=120,lowpass=f=7000[bgm];[1:a]volume=0.92,apad=pad_dur=${tail_seconds}[voice];[bgm][voice]amix=inputs=2:duration=shortest:dropout_transition=0,alimiter=limit=0.80,aresample=16000[a]" \
			-map "[a]" -ac 1 -ar 16000 -c:a pcm_s16le "$work_dir/$name.mixed.wav"
		render_audio="$work_dir/$name.mixed.wav"
	fi
	ffmpeg -nostdin -hide_banner -loglevel error -y -i "$render_audio" \
		-map_metadata -1 -fflags +bitexact -flags:a +bitexact \
		-ac 1 -ar 8000 -c:a pcm_s16le -f wav "$output_dir/$name.wav"
	ffmpeg -nostdin -hide_banner -loglevel error -y -i "$render_audio" \
		-map_metadata -1 -fflags +bitexact -flags:a +bitexact \
		-ac 1 -ar 16000 -c:a pcm_s16le -f wav "$output_dir/$name.wav16"
	test -s "$output_dir/$name.wav"
	test -s "$output_dir/$name.wav16"
done

printf '%s\n' "Generated Asterisk 8/16 kHz prompts with $engine; IVR prompts include approved BGM from $bgm_file"
